<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds nick lifecycle states to registered_nicks.
 *
 * SQLite does not support ALTER COLUMN, so the table is recreated:
 *   - status        VARCHAR(20)  NOT NULL DEFAULT 'registered'
 *   - expires_at    DATETIME     NULL  (PENDING TTL)
 *   - reason        VARCHAR(512) NULL  (SUSPENDED / FORBIDDEN reason)
 *   - password_hash → nullable  (FORBIDDEN entries have no password)
 *   - email         → nullable  (FORBIDDEN entries have no email)
 *   - registered_at → nullable  (FORBIDDEN entries have no registration date)
 *
 * Existing rows are migrated with status='registered' and nulls for new columns.
 */
final class Version20260221000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status, expires_at and reason columns to registered_nicks; make password_hash, email and registered_at nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE registered_nicks_new (
                    id                INTEGER      PRIMARY KEY AUTOINCREMENT NOT NULL,
                    nickname          VARCHAR(32)  NOT NULL,
                    nickname_lower    VARCHAR(32)  NOT NULL,
                    status            VARCHAR(20)  NOT NULL DEFAULT 'registered',
                    password_hash     VARCHAR(255) DEFAULT NULL,
                    email             VARCHAR(255) DEFAULT NULL,
                    language          VARCHAR(10)  NOT NULL DEFAULT 'en',
                    registered_at     DATETIME     DEFAULT NULL,
                    expires_at        DATETIME     DEFAULT NULL,
                    reason            VARCHAR(512) DEFAULT NULL,
                    last_seen_at      DATETIME     DEFAULT NULL,
                    last_quit_message VARCHAR(512) DEFAULT NULL,
                    private           BOOLEAN      NOT NULL DEFAULT 0
                )
            SQL);

        $this->addSql(<<<'SQL'
                INSERT INTO registered_nicks_new (
                    id, nickname, nickname_lower, status,
                    password_hash, email, language, registered_at,
                    expires_at, reason, last_seen_at, last_quit_message, private
                )
                SELECT
                    id, nickname, nickname_lower, 'registered',
                    password_hash, email, language, registered_at,
                    NULL, NULL, last_seen_at, last_quit_message, private
                FROM registered_nicks
            SQL);

        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('ALTER TABLE registered_nicks_new RENAME TO registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE registered_nicks_old (
                    id                INTEGER      PRIMARY KEY AUTOINCREMENT NOT NULL,
                    nickname          VARCHAR(32)  NOT NULL,
                    nickname_lower    VARCHAR(32)  NOT NULL,
                    password_hash     VARCHAR(255) NOT NULL,
                    email             VARCHAR(255) NOT NULL,
                    language          VARCHAR(10)  NOT NULL DEFAULT 'en',
                    registered_at     DATETIME     NOT NULL,
                    last_seen_at      DATETIME     DEFAULT NULL,
                    last_quit_message VARCHAR(512) DEFAULT NULL,
                    private           BOOLEAN      NOT NULL DEFAULT 0
                )
            SQL);

        $this->addSql(<<<'SQL'
                INSERT INTO registered_nicks_old (
                    id, nickname, nickname_lower, password_hash,
                    email, language, registered_at,
                    last_seen_at, last_quit_message, private
                )
                SELECT
                    id, nickname, nickname_lower,
                    COALESCE(password_hash, ''),
                    COALESCE(email, ''),
                    language,
                    COALESCE(registered_at, datetime('now')),
                    last_seen_at, last_quit_message, private
                FROM registered_nicks
                WHERE status = 'registered'
            SQL);

        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('ALTER TABLE registered_nicks_old RENAME TO registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
    }
}
