<?php

declare(strict_types=1);

namespace App\Idempotency\Outcome;

use App\Idempotency\StoredResponse;

final readonly class Completed implements BeginOutcome
{
    public function __construct(public StoredResponse $response) {}
}
