<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

/**
 * Steps a stored payload from its written schema version to the current one by
 * applying single-step {@see Upcaster}s in version order. Ambiguity (two
 * upcasters for the same step) fails at construction; a gap fails at read time
 * ({@see MissingUpcaster}) — never silently.
 */
final class UpcasterChain
{
    /** @var array<string, array<int, Upcaster>> type => fromVersion => upcaster */
    private array $upcasters = [];

    /**
     * @param iterable<Upcaster> $upcasters
     */
    public function __construct(iterable $upcasters = [])
    {
        foreach ($upcasters as $upcaster) {
            $type = $upcaster->eventType();
            $from = $upcaster->fromVersion();
            if (isset($this->upcasters[$type][$from])) {
                throw MissingUpcaster::duplicateStep($type, $from);
            }
            $this->upcasters[$type][$from] = $upcaster;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(string $type, int $fromVersion, int $toVersion, array $payload): array
    {
        for ($version = $fromVersion; $version < $toVersion; ++$version) {
            $upcaster = $this->upcasters[$type][$version] ?? throw MissingUpcaster::forStep($type, $version);
            $payload = $upcaster->upcast($payload);
        }

        return $payload;
    }
}
