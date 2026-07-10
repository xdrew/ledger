<?php

declare(strict_types=1);

namespace App\Projections\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Tracks the projection runner's last processed global position.
 */
final readonly class CheckpointStore
{
    private const string DEFAULT_NAME = 'default';

    public function __construct(private Connection $connection) {}

    public function position(string $name = self::DEFAULT_NAME): int
    {
        $raw = $this->connection->fetchOne(
            'SELECT position FROM projection_checkpoints WHERE name = :name',
            ['name' => $name],
        );

        return is_numeric($raw) ? (int) $raw : 0;
    }

    public function save(int $position, string $name = self::DEFAULT_NAME): void
    {
        $this->connection->executeStatement(
            'INSERT INTO projection_checkpoints (name, position, updated_at)
             VALUES (:name, :position, now())
             ON CONFLICT (name) DO UPDATE SET position = EXCLUDED.position, updated_at = now()',
            ['name' => $name, 'position' => $position],
            ['position' => ParameterType::INTEGER],
        );
    }

    public function reset(string $name = self::DEFAULT_NAME): void
    {
        $this->save(0, $name);
    }
}
