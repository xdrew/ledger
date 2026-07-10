<?php

declare(strict_types=1);

namespace App\Projections;

use App\EventStore\EventStore;
use App\Observability\Tracing\NoopTracer;
use App\Observability\Tracing\Tracer;
use App\Projections\Dbal\CheckpointStore;
use Doctrine\DBAL\Connection;

/**
 * Catch-up subscription: reads the event store's global stream from the checkpoint
 * and folds events into the read models. Each batch advances the checkpoint in the
 * same transaction as the read-model writes, so processing is exactly-once and
 * replay-safe even though balance updates are incremental.
 */
final readonly class ProjectionRunner
{
    private const int BATCH_SIZE = 500;

    private Tracer $tracer;

    /**
     * @param iterable<Projector> $projectors
     */
    public function __construct(
        private EventStore $eventStore,
        private Connection $connection,
        private CheckpointStore $checkpoints,
        private iterable $projectors,
        ?Tracer $tracer = null,
    ) {
        $this->tracer = $tracer ?? new NoopTracer();
    }

    /**
     * Process all events from the checkpoint to the head. Returns the number of
     * events processed.
     */
    public function run(): int
    {
        $processed = 0;

        while (true) {
            $events = $this->eventStore->readFrom($this->checkpoints->position(), self::BATCH_SIZE);
            if ($events === []) {
                break;
            }

            $this->connection->transactional(function () use ($events): void {
                $lastPosition = 0;
                foreach ($events as $event) {
                    $this->tracer->continueTrace(
                        $event->metadata->traceparent,
                        'projection.project',
                        function () use ($event): void {
                            foreach ($this->projectors as $projector) {
                                if ($projector->handles($event)) {
                                    $projector->project($event);
                                }
                            }
                        },
                        ['event_type' => $event->eventType],
                    );
                    $lastPosition = $event->globalPosition ?? $lastPosition;
                }
                if ($lastPosition > 0) {
                    $this->checkpoints->save($lastPosition);
                }
            });

            $processed += \count($events);
            if (\count($events) < self::BATCH_SIZE) {
                break;
            }
        }

        return $processed;
    }

    /**
     * Truncate the read models, reset the checkpoint, and replay from the start.
     */
    public function rebuild(): int
    {
        $this->connection->executeStatement('TRUNCATE account_balances, account_statement');
        $this->checkpoints->reset();

        return $this->run();
    }

    public function position(): int
    {
        return $this->checkpoints->position();
    }

    public function head(): int
    {
        $raw = $this->connection->fetchOne('SELECT COALESCE(MAX(global_position), 0) FROM events');

        return is_numeric($raw) ? (int) $raw : 0;
    }

    public function lag(): int
    {
        return max(0, $this->head() - $this->checkpoints->position());
    }
}
