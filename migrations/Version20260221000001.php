<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial NickServ schema: registered_nicks table.
 */
final class Version20260221000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create registered_nicks table for NickServ';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE registered_nicks (
                id               INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                nickname         VARCHAR(32)  NOT NULL,
                nickname_lower   VARCHAR(32)  NOT NULL,
                password_hash    VARCHAR(255) NOT NULL,
                email            VARCHAR(255) NOT NULL,
                language         VARCHAR(10)  NOT NULL DEFAULT 'en',
                registered_at    DATETIME     NOT NULL,
                last_seen_at     DATETIME     DEFAULT NULL,
                last_quit_message VARCHAR(512) DEFAULT NULL,
                private          BOOLEAN      NOT NULL DEFAULT 0
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registered_nicks');
    }
}
