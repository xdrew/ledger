<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

/**
 * Marks an Action class (or its `__invoke`) as a public endpoint that needs no
 * API key, so the generator omits its security requirement and the firewall
 * lets it through.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class OpenApiPublic {}
