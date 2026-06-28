<?php

declare(strict_types=1);

namespace App\Projections;

use App\EventStore\RecordedEvent;

/**
 * Folds domain events into a read model. Implementations must be replay-safe and
 * side-effect-free except through their read-model writes (determinism).
 */
interface Projector
{
    public function handles(RecordedEvent $event): bool;

    public function project(RecordedEvent $event): void;
}
