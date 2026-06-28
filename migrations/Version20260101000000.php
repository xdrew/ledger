<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the append-only event store table.
 *
 * - `global_position` is a store-wide monotonic identity (PK) for ordered reads.
 * - UNIQUE (stream_type, stream_id, version) enforces optimistic concurrency.
 * - `payload`/`metadata` are JSONB; rows are never updated or deleted.
 */
final class Version20260101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create append-only events table (event store foundation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE events (
                global_position BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                event_id        UUID         NOT NULL,
                stream_type     VARCHAR(128) NOT NULL,
                stream_id       VARCHAR(128) NOT NULL,
                version         INT          NOT NULL,
                event_type      VARCHAR(255) NOT NULL,
                schema_version  INT          NOT NULL DEFAULT 1,
                payload         JSONB        NOT NULL,
                metadata        JSONB        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at     TIMESTAMPTZ  NOT NULL,
                recorded_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_events_stream_version ON events (stream_type, stream_id, version)');
        $this->addSql('CREATE UNIQUE INDEX uniq_events_event_id ON events (event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE events');
    }
}
