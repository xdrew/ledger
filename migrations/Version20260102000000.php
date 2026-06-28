<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the idempotency_keys table — a plain mutable table (not event-sourced).
 *
 * PRIMARY KEY (idempotency_key, route) is the unique constraint and the
 * INSERT ... ON CONFLICT target that makes claiming a key atomic.
 */
final class Version20260102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create idempotency_keys table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE idempotency_keys (
                idempotency_key  VARCHAR(255) NOT NULL,
                route            VARCHAR(255) NOT NULL,
                request_hash     VARCHAR(128) NOT NULL,
                status           VARCHAR(16)  NOT NULL,
                response_status  INT,
                response_headers JSONB,
                response_body    TEXT,
                created_at       TIMESTAMPTZ  NOT NULL,
                completed_at     TIMESTAMPTZ,
                expires_at       TIMESTAMPTZ,
                PRIMARY KEY (idempotency_key, route)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_idempotency_expires_at ON idempotency_keys (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idempotency_keys');
    }
}
