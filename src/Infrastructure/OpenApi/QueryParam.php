<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

/**
 * Documents a query-string parameter on an Action (repeatable).
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class QueryParam
{
    /**
     * @param list<string> $enum
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $required = false,
        public string $description = '',
        public array $enum = [],
    ) {}
}
