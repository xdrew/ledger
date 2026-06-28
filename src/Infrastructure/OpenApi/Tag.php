<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

/**
 * Overrides the OpenAPI tag (operation grouping) for an Action class; defaults
 * to the module name derived from the namespace.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Tag
{
    public function __construct(public string $name) {}
}
