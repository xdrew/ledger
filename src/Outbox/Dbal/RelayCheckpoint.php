<?php

declare(strict_types=1);

namespace App\Outbox\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The outbox relay's published-position cursor (a row named "outbox" in the
 * shared checkpoint table).
 */
final class RelayCheckpoint
{
    private const NAME = 'outbox';

    public function __construct(private readonly Connection $connection) {}

    public function position(): int
    {
        $raw = $this->connection->fetchOne(
            'SELECT position FROM projection_checkpoints WHERE name = :name',
            ['name' => self::NAME],
        );

        return is_numeric($raw) ? (int) $raw : 0;
    }

    public function save(int $position): void
    {
        $this->connection->executeStatement(
            'INSERT INTO projection_checkpoints (name, position, updated_at)
             VALUES (:name, :position, now())
             ON CONFLICT (name) DO UPDATE SET position = EXCLUDED.position, updated_at = now()',
            ['name' => self::NAME, 'position' => $position],
            ['position' => ParameterType::INTEGER],
        );
    }
}
