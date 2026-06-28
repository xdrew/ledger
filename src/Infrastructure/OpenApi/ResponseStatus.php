<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

/**
 * Declares the success HTTP status of an endpoint (default 200), used by the
 * generator and applied by the action when it builds its response.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ResponseStatus
{
    public function __construct(public int $code) {}
}
