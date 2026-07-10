<?php

declare(strict_types=1);

namespace App\Idempotency\Dbal;

use App\Idempotency\IdempotencyKey;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\Outcome\BeginOutcome;
use App\Idempotency\Outcome\Begun;
use App\Idempotency\Outcome\Completed;
use App\Idempotency\Outcome\InProgress;
use App\Idempotency\Outcome\Mismatch;
use App\Idempotency\StoredResponse;
use App\Idempotency\Ttl;
use App\SharedKernel\Clock\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * PostgreSQL idempotency store — a plain mutable table (no event sourcing).
 *
 * `begin` claims the key atomically with INSERT ... ON CONFLICT DO NOTHING, so
 * exactly one concurrent caller wins. An existing row is then classified
 * (expired-or-stale-reclaim / mismatch / in-progress / completed).
 */
final readonly class DbalIdempotencyStore implements IdempotencyStore
{
    private const string STATUS_IN_PROGRESS = 'in_progress';
    private const string STATUS_COMPLETED = 'completed';
    private const string TS = 'Y-m-d H:i:s.uP';
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Connection $connection,
        private Clock $clock,
        private Ttl $ttl,
    ) {}

    public function begin(IdempotencyKey $key, string $route, string $requestHash): BeginOutcome
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $instant = $this->clock->now();
            $now = $instant->format(self::TS);
            $staleBefore = $instant->sub(new \DateInterval('PT' . self::STALE_IN_PROGRESS_SECONDS . 'S'))->format(self::TS);

            $inserted = $this->connection->executeStatement(
                'INSERT INTO idempotency_keys (idempotency_key, route, request_hash, status, created_at)
                 VALUES (:key, :route, :hash, :status, :now)
                 ON CONFLICT (idempotency_key, route) DO NOTHING',
                ['key' => $key->value, 'route' => $route, 'hash' => $requestHash, 'status' => self::STATUS_IN_PROGRESS, 'now' => $now],
            );
            if ($inserted === 1) {
                return new Begun();
            }

            // Reclaim an expired completed row, or a stale in-progress row whose
            // owner died before completing or releasing (atomic; the WHERE only
            // matches reclaimable rows).
            $reclaimed = $this->connection->executeStatement(
                'UPDATE idempotency_keys
                 SET request_hash = :hash, status = :in_progress, created_at = :now,
                     completed_at = NULL, response_status = NULL, response_headers = NULL,
                     response_body = NULL, expires_at = NULL
                 WHERE idempotency_key = :key AND route = :route
                   AND ((status = :completed AND expires_at < :now)
                     OR (status = :in_progress AND created_at < :stale_before))',
                [
                    'key' => $key->value, 'route' => $route, 'hash' => $requestHash, 'now' => $now,
                    'stale_before' => $staleBefore,
                    'in_progress' => self::STATUS_IN_PROGRESS, 'completed' => self::STATUS_COMPLETED,
                ],
            );
            if ($reclaimed === 1) {
                return new Begun();
            }

            $row = $this->connection->fetchAssociative(
                'SELECT request_hash, status, response_status, response_headers, response_body
                 FROM idempotency_keys WHERE idempotency_key = :key AND route = :route',
                ['key' => $key->value, 'route' => $route],
            );
            if ($row === false) {
                // The row vanished between the conflict and the read (a concurrent
                // reclaim+complete); retry the claim.
                continue;
            }

            if (self::asString($row['request_hash'] ?? '') !== $requestHash) {
                return new Mismatch();
            }

            if (self::asString($row['status'] ?? '') === self::STATUS_IN_PROGRESS) {
                return new InProgress();
            }

            return new Completed($this->storedResponse($row));
        }

        // Exhausted retries against a racing reclaim — treat as in-flight.
        return new InProgress();
    }

    public function complete(IdempotencyKey $key, string $route, StoredResponse $response): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            'UPDATE idempotency_keys
             SET status = :completed, response_status = :rs,
                 response_headers = CAST(:rh AS JSONB), response_body = :rb,
                 completed_at = :now, expires_at = :exp
             WHERE idempotency_key = :key AND route = :route',
            [
                'completed' => self::STATUS_COMPLETED,
                'rs' => $response->status,
                'rh' => json_encode($response->headers, JSON_THROW_ON_ERROR),
                'rb' => $response->body,
                'now' => $now->format(self::TS),
                'exp' => $this->ttl->expiresFrom($now)->format(self::TS),
                'key' => $key->value,
                'route' => $route,
            ],
            ['rs' => ParameterType::INTEGER],
        );
    }

    public function release(IdempotencyKey $key, string $route): void
    {
        // Guarded on status so a completed row (e.g. finished by a competitor
        // that reclaimed this key as stale) keeps its replayable response.
        $this->connection->executeStatement(
            'DELETE FROM idempotency_keys
             WHERE idempotency_key = :key AND route = :route AND status = :in_progress',
            ['key' => $key->value, 'route' => $route, 'in_progress' => self::STATUS_IN_PROGRESS],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function storedResponse(array $row): StoredResponse
    {
        return new StoredResponse(
            self::asInt($row['response_status'] ?? 0),
            $this->decodeHeaders(self::asString($row['response_headers'] ?? '{}')),
            self::asString($row['response_body'] ?? ''),
        );
    }

    /**
     * @return array<string, string>
     */
    private function decodeHeaders(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $headers = [];
        if (\is_array($decoded)) {
            foreach ($decoded as $name => $value) {
                if (\is_string($name) && \is_string($value)) {
                    $headers[$name] = $value;
                }
            }
        }

        return $headers;
    }

    private static function asString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function asInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
