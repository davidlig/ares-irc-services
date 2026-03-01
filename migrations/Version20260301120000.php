<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ChanServ: registered_channels, channel_access, channel_levels.
 */
final class Version20260301120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ChanServ: add registered_channels, channel_access, channel_levels tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registered_channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(64) NOT NULL,
            name_lower VARCHAR(64) NOT NULL,
            founder_nick_id INTEGER NOT NULL,
            successor_nick_id INTEGER DEFAULT NULL,
            description VARCHAR(255) NOT NULL DEFAULT "",
            url VARCHAR(255) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            entrymsg VARCHAR(255) NOT NULL DEFAULT "",
            topic_lock BOOLEAN NOT NULL DEFAULT 0,
            mlock VARCHAR(64) NOT NULL DEFAULT "",
            secure BOOLEAN NOT NULL DEFAULT 0,
            topic CLOB DEFAULT NULL,
            last_topic_set_at DATETIME DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_chanserv_name_lower ON registered_channels (name_lower)');
        $this->addSql('CREATE INDEX idx_chanserv_founder ON registered_channels (founder_nick_id)');

        $this->addSql('CREATE TABLE channel_access (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            channel_id INTEGER NOT NULL,
            nick_id INTEGER NOT NULL,
            level INTEGER NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_nick ON channel_access (channel_id, nick_id)');
        $this->addSql('CREATE INDEX idx_channel_access_channel ON channel_access (channel_id)');

        $this->addSql('CREATE TABLE channel_levels (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            channel_id INTEGER NOT NULL,
            level_key VARCHAR(32) NOT NULL,
            value INTEGER NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_level_key ON channel_levels (channel_id, level_key)');
        $this->addSql('CREATE INDEX idx_channel_levels_channel ON channel_levels (channel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registered_channels');
        $this->addSql('DROP TABLE channel_access');
        $this->addSql('DROP TABLE channel_levels');
    }
}
