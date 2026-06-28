<?php

declare(strict_types=1);

namespace App\Idempotency\Outcome;

use App\Idempotency\StoredResponse;

final class Completed implements BeginOutcome
{
    public function __construct(public readonly StoredResponse $response) {}
}
