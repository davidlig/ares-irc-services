<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema for Ares IRC Services.
 *
 * Creates all tables for NickServ, ChanServ, MemoServ, OperServ and Symfony Messenger.
 * Foreign keys are included where Doctrine mapping requires them (OperServ relationships).
 */
final class Version20260301000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: NickServ, ChanServ, MemoServ, OperServ, Messenger tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, msg_privmsg BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F375ED32E93 ON registered_nicks (vhost)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');

        $this->addSql('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(64) NOT NULL, name_lower VARCHAR(64) NOT NULL, founder_nick_id INTEGER NOT NULL, successor_nick_id INTEGER DEFAULT NULL, description VARCHAR(255) NOT NULL, url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, entrymsg VARCHAR(255) NOT NULL, topic_lock BOOLEAN NOT NULL, mlock_active BOOLEAN NOT NULL, mlock VARCHAR(64) NOT NULL, mlock_params CLOB NOT NULL, secure BOOLEAN NOT NULL, topic CLOB DEFAULT NULL, last_topic_set_at DATETIME DEFAULT NULL, last_topic_set_by_nick VARCHAR(64) DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F13B8A6C0BC2966 ON registered_channels (name_lower)');
        $this->addSql('CREATE INDEX idx_name_lower ON registered_channels (name_lower)');
        $this->addSql('CREATE INDEX idx_registered_channels_successor_nick_id ON registered_channels (successor_nick_id)');

        $this->addSql('CREATE TABLE channel_access (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, channel_id INTEGER NOT NULL, nick_id INTEGER NOT NULL, level INTEGER NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_nick ON channel_access (channel_id, nick_id)');
        $this->addSql('CREATE INDEX idx_channel_access_nick_id ON channel_access (nick_id)');

        $this->addSql('CREATE TABLE channel_levels (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, channel_id INTEGER NOT NULL, level_key VARCHAR(32) NOT NULL, value INTEGER NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_key ON channel_levels (channel_id, level_key)');

        $this->addSql('CREATE TABLE memos (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, sender_nick_id INTEGER NOT NULL, message CLOB NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_memos_target_nick ON memos (target_nick_id)');
        $this->addSql('CREATE INDEX idx_memos_target_channel ON memos (target_channel_id)');
        $this->addSql('CREATE INDEX idx_memos_sender ON memos (sender_nick_id)');

        $this->addSql('CREATE TABLE memo_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, enabled BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_settings_nick ON memo_settings (target_nick_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_settings_channel ON memo_settings (target_channel_id)');

        $this->addSql('CREATE TABLE memo_ignores (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, ignored_nick_id INTEGER NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_ignore_target_ignored ON memo_ignores (target_nick_id, target_channel_id, ignored_nick_id)');

        $this->addSql('CREATE TABLE oper_roles (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(32) NOT NULL, description VARCHAR(255) NOT NULL, protected BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_role_name ON oper_roles (name)');

        $this->addSql('CREATE TABLE oper_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(64) NOT NULL, description VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_permission_name ON oper_permissions (name)');

        $this->addSql('CREATE TABLE oper_role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, PRIMARY KEY (role_id, permission_id), CONSTRAINT FK_47D48116D60322AC FOREIGN KEY (role_id) REFERENCES oper_roles (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_47D48116FED90CCA FOREIGN KEY (permission_id) REFERENCES oper_permissions (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_47D48116D60322AC ON oper_role_permissions (role_id)');
        $this->addSql('CREATE INDEX IDX_47D48116FED90CCA ON oper_role_permissions (permission_id)');

        $this->addSql('CREATE TABLE oper_ircops (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nick_id INTEGER NOT NULL, role_id INTEGER NOT NULL, added_at DATETIME NOT NULL, added_by_id INTEGER DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, CONSTRAINT FK_533CFD6FD60322AC FOREIGN KEY (role_id) REFERENCES oper_roles (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ircop_nick ON oper_ircops (nick_id)');
        $this->addSql('CREATE INDEX idx_ircop_role ON oper_ircops (role_id)');

        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE oper_ircops');
        $this->addSql('DROP TABLE oper_role_permissions');
        $this->addSql('DROP TABLE oper_permissions');
        $this->addSql('DROP TABLE oper_roles');
        $this->addSql('DROP TABLE memo_ignores');
        $this->addSql('DROP TABLE memo_settings');
        $this->addSql('DROP TABLE memos');
        $this->addSql('DROP TABLE channel_levels');
        $this->addSql('DROP TABLE channel_access');
        $this->addSql('DROP TABLE registered_channels');
        $this->addSql('DROP TABLE registered_nicks');
    }
}
