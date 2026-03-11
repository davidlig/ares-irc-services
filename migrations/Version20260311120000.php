<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MemoServ: memos, memo_ignores, memo_settings tables.
 * No foreign keys to registered_nicks/registered_channels; cleanup via NickDropEvent/ChannelDropEvent.
 */
final class Version20260311120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MemoServ: add memos, memo_ignores, memo_settings tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE memos (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            target_nick_id INTEGER DEFAULT NULL,
            target_channel_id INTEGER DEFAULT NULL,
            sender_nick_id INTEGER NOT NULL,
            message CLOB NOT NULL,
            created_at DATETIME NOT NULL,
            read_at DATETIME DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX idx_memos_target_nick ON memos (target_nick_id)');
        $this->addSql('CREATE INDEX idx_memos_target_channel ON memos (target_channel_id)');
        $this->addSql('CREATE INDEX idx_memos_sender ON memos (sender_nick_id)');

        $this->addSql('CREATE TABLE memo_ignores (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            target_nick_id INTEGER DEFAULT NULL,
            target_channel_id INTEGER DEFAULT NULL,
            ignored_nick_id INTEGER NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_ignore_target_ignored ON memo_ignores (target_nick_id, target_channel_id, ignored_nick_id)');
        $this->addSql('CREATE INDEX idx_memo_ignores_target_nick ON memo_ignores (target_nick_id)');
        $this->addSql('CREATE INDEX idx_memo_ignores_target_channel ON memo_ignores (target_channel_id)');

        $this->addSql('CREATE TABLE memo_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            target_nick_id INTEGER DEFAULT NULL,
            target_channel_id INTEGER DEFAULT NULL,
            enabled BOOLEAN NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_settings_nick ON memo_settings (target_nick_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_settings_channel ON memo_settings (target_channel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE memos');
        $this->addSql('DROP TABLE memo_ignores');
        $this->addSql('DROP TABLE memo_settings');
    }
}
