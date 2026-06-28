<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

use App\SharedKernel\Event\DomainEvent;

/**
 * Bidirectional map between stable event type strings (e.g. "accounts.account_opened")
 * and event classes, plus each type's current schema version. Decouples stored
 * data from PHP class names and is the seam upcasters will hook into later.
 */
final class EventTypeRegistry
{
    /** @var array<string, class-string<DomainEvent>> */
    private array $typeToClass = [];

    /** @var array<class-string<DomainEvent>, string> */
    private array $classToType = [];

    /** @var array<class-string<DomainEvent>, int> */
    private array $classToSchemaVersion = [];

    /**
     * @param class-string<DomainEvent> $class
     */
    public function register(string $type, string $class, int $schemaVersion = 1): void
    {
        $this->typeToClass[$type] = $class;
        $this->classToType[$class] = $type;
        $this->classToSchemaVersion[$class] = $schemaVersion;
    }

    /**
     * @return class-string<DomainEvent>
     */
    public function classForType(string $type): string
    {
        return $this->typeToClass[$type] ?? throw UnknownEventType::forType($type);
    }

    /**
     * @param class-string<DomainEvent> $class
     */
    public function typeForClass(string $class): string
    {
        return $this->classToType[$class] ?? throw UnknownEventType::forClass($class);
    }

    /**
     * @param class-string<DomainEvent> $class
     */
    public function schemaVersionForClass(string $class): int
    {
        return $this->classToSchemaVersion[$class] ?? throw UnknownEventType::forClass($class);
    }
}
