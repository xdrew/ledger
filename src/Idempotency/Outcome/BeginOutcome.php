<?php

declare(strict_types=1);

namespace App\Idempotency\Outcome;

use App\Idempotency\IdempotencyStore;

/**
 * Result of {@see IdempotencyStore::begin()}. One of
 * {@see Begun}, {@see InProgress}, {@see Mismatch}, {@see Completed}.
 */
interface BeginOutcome {}
