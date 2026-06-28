<?php

declare(strict_types=1);

namespace App\EventStore\Dbal;

use App\EventStore\ConcurrencyConflict;
use App\EventStore\EventMetadata;
use App\EventStore\EventStore;
use App\EventStore\RecordedEvent;
use App\EventStore\Serialization\EventSerializer;
use App\EventStore\StreamId;
use App\SharedKernel\Clock\Clock;
use App\SharedKernel\Event\EventId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * PostgreSQL event store on Doctrine DBAL (raw SQL, no ORM).
 *
 * Each append runs in a single transaction: it checks the current version, then
 * inserts the events. The UNIQUE (stream_type, stream_id, version) constraint is
 * the real optimistic-concurrency guard — a lost race surfaces as a unique
 * violation, which is translated into {@see ConcurrencyConflict}. Nothing is
 * persisted on conflict.
 */
final class DbalEventStore implements EventStore
{
    private const INSERT_SQL = <<<'SQL'
        INSERT INTO events
            (event_id, stream_type, stream_id, version, event_type, schema_version, payload, metadata, occurred_at)
        VALUES
            (:event_id, :stream_type, :stream_id, :version, :event_type, :schema_version, CAST(:payload AS JSONB), CAST(:metadata AS JSONB), :occurred_at)
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly EventSerializer $serializer,
        private readonly Clock $clock,
    ) {}

    public function append(StreamId $streamId, int $expectedVersion, array $events, ?EventMetadata $metadata = null): void
    {
        if ($events === []) {
            return;
        }

        $metadata ??= EventMetadata::none();

        $this->connection->beginTransaction();

        try {
            $current = $this->currentVersion($streamId);
            if ($current !== $expectedVersion) {
                throw ConcurrencyConflict::forStream($streamId, $expectedVersion, $current);
            }

            $version = $expectedVersion;
            foreach ($events as $event) {
                ++$version;
                $serialized = $this->serializer->serialize($event);

                $this->connection->executeStatement(
                    self::INSERT_SQL,
                    [
                        'event_id' => EventId::generate()->toString(),
                        'stream_type' => $streamId->type,
                        'stream_id' => $streamId->id,
                        'version' => $version,
                        'event_type' => $serialized->type,
                        'schema_version' => $serialized->schemaVersion,
                        'payload' => $this->encodeJson($serialized->payload),
                        'metadata' => $this->encodeJson([
                            'correlation_id' => $metadata->correlationId,
                            'causation_id' => $metadata->causationId,
                        ]),
                        'occurred_at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
                    ],
                    [
                        'version' => ParameterType::INTEGER,
                        'schema_version' => ParameterType::INTEGER,
                    ],
                );
            }

            $this->connection->commit();
        } catch (UniqueConstraintViolationException) {
            $this->connection->rollBack();

            throw ConcurrencyConflict::forStream($streamId, $expectedVersion, $this->currentVersion($streamId));
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    public function load(StreamId $streamId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM events WHERE stream_type = :type AND stream_id = :id ORDER BY version ASC',
            ['type' => $streamId->type, 'id' => $streamId->id],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function readFrom(int $afterPosition, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM events WHERE global_position > :after ORDER BY global_position ASC LIMIT :limit',
            ['after' => $afterPosition, 'limit' => $limit],
            ['after' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map($this->hydrate(...), $rows);
    }

    private function currentVersion(StreamId $streamId): int
    {
        $raw = $this->connection->fetchOne(
            'SELECT COALESCE(MAX(version), 0) FROM events WHERE stream_type = :type AND stream_id = :id',
            ['type' => $streamId->type, 'id' => $streamId->id],
        );

        return self::asInt($raw);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RecordedEvent
    {
        $type = self::asString($row['event_type'] ?? '');
        $schemaVersion = self::asInt($row['schema_version'] ?? 1);
        $payload = $this->decodeJson(self::asString($row['payload'] ?? '{}'));
        $rawMetadata = $this->decodeJson(self::asString($row['metadata'] ?? '{}'));

        return new RecordedEvent(
            EventId::fromString(self::asString($row['event_id'] ?? '')),
            StreamId::of(self::asString($row['stream_type'] ?? ''), self::asString($row['stream_id'] ?? '')),
            self::asInt($row['version'] ?? 0),
            $type,
            $schemaVersion,
            $this->serializer->deserialize($type, $schemaVersion, $payload),
            new \DateTimeImmutable(self::asString($row['occurred_at'] ?? 'now')),
            new EventMetadata(
                self::asNullableString($rawMetadata['correlation_id'] ?? null),
                self::asNullableString($rawMetadata['causation_id'] ?? null),
            ),
            self::asInt($row['global_position'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $result */
        $result = \is_array($decoded) ? $decoded : [];

        return $result;
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

    private static function asNullableString(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
