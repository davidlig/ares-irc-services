<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260316192833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oper_admins (nick_id INTEGER NOT NULL, added_at DATETIME NOT NULL, added_by_id INTEGER DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, role_id INTEGER NOT NULL, CONSTRAINT FK_60DC297ED60322AC FOREIGN KEY (role_id) REFERENCES oper_roles (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_admin_role ON oper_admins (role_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_nick ON oper_admins (nick_id)');
        $this->addSql('CREATE TABLE oper_permissions (name VARCHAR(64) NOT NULL, description VARCHAR(255) NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_permission_name ON oper_permissions (name)');
        $this->addSql('CREATE TABLE oper_roles (name VARCHAR(32) NOT NULL, description VARCHAR(255) NOT NULL, protected BOOLEAN NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_role_name ON oper_roles (name)');
        $this->addSql('CREATE TABLE oper_role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, PRIMARY KEY (role_id, permission_id), CONSTRAINT FK_47D48116D60322AC FOREIGN KEY (role_id) REFERENCES oper_roles (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_47D48116FED90CCA FOREIGN KEY (permission_id) REFERENCES oper_permissions (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_47D48116D60322AC ON oper_role_permissions (role_id)');
        $this->addSql('CREATE INDEX IDX_47D48116FED90CCA ON oper_role_permissions (permission_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__channel_access AS SELECT channel_id, nick_id, level, id FROM channel_access');
        $this->addSql('DROP TABLE channel_access');
        $this->addSql('CREATE TABLE channel_access (channel_id INTEGER NOT NULL, nick_id INTEGER NOT NULL, level INTEGER NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO channel_access (channel_id, nick_id, level, id) SELECT channel_id, nick_id, level, id FROM __temp__channel_access');
        $this->addSql('DROP TABLE __temp__channel_access');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_nick ON channel_access (channel_id, nick_id)');
        $this->addSql('CREATE INDEX idx_channel_access_nick_id ON channel_access (nick_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__channel_levels AS SELECT channel_id, level_key, value, id FROM channel_levels');
        $this->addSql('DROP TABLE channel_levels');
        $this->addSql('CREATE TABLE channel_levels (channel_id INTEGER NOT NULL, level_key VARCHAR(32) NOT NULL, value INTEGER NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO channel_levels (channel_id, level_key, value, id) SELECT channel_id, level_key, value, id FROM __temp__channel_levels');
        $this->addSql('DROP TABLE __temp__channel_levels');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_key ON channel_levels (channel_id, level_key)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__memo_ignores AS SELECT id, target_nick_id, target_channel_id, ignored_nick_id FROM memo_ignores');
        $this->addSql('DROP TABLE memo_ignores');
        $this->addSql('CREATE TABLE memo_ignores (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, ignored_nick_id INTEGER NOT NULL)');
        $this->addSql('INSERT INTO memo_ignores (id, target_nick_id, target_channel_id, ignored_nick_id) SELECT id, target_nick_id, target_channel_id, ignored_nick_id FROM __temp__memo_ignores');
        $this->addSql('DROP TABLE __temp__memo_ignores');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_ignore_target_ignored ON memo_ignores (target_nick_id, target_channel_id, ignored_nick_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__memo_settings AS SELECT id, target_nick_id, target_channel_id, enabled FROM memo_settings');
        $this->addSql('DROP TABLE memo_settings');
        $this->addSql('CREATE TABLE memo_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, enabled BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO memo_settings (id, target_nick_id, target_channel_id, enabled) SELECT id, target_nick_id, target_channel_id, enabled FROM __temp__memo_settings');
        $this->addSql('DROP TABLE __temp__memo_settings');
        $this->addSql('CREATE TEMPORARY TABLE __temp__memos AS SELECT id, target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at FROM memos');
        $this->addSql('DROP TABLE memos');
        $this->addSql('CREATE TABLE memos (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, sender_nick_id INTEGER NOT NULL, message CLOB NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO memos (id, target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at) SELECT id, target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at FROM __temp__memos');
        $this->addSql('DROP TABLE __temp__memos');
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_channels AS SELECT name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id FROM registered_channels');
        $this->addSql('DROP TABLE registered_channels');
        $this->addSql('CREATE TABLE registered_channels (name VARCHAR(64) NOT NULL, name_lower VARCHAR(64) NOT NULL, founder_nick_id INTEGER NOT NULL, successor_nick_id INTEGER DEFAULT NULL, description VARCHAR(255) NOT NULL, url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, entrymsg VARCHAR(255) NOT NULL, topic_lock BOOLEAN NOT NULL, mlock_active BOOLEAN NOT NULL, mlock VARCHAR(64) NOT NULL, mlock_params CLOB NOT NULL, secure BOOLEAN NOT NULL, topic CLOB DEFAULT NULL, last_topic_set_at DATETIME DEFAULT NULL, last_topic_set_by_nick VARCHAR(64) DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_channels (name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id) SELECT name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id FROM __temp__registered_channels');
        $this->addSql('DROP TABLE __temp__registered_channels');
        $this->addSql('CREATE INDEX idx_name_lower ON registered_channels (name_lower)');
        $this->addSql('CREATE INDEX idx_registered_channels_successor_nick_id ON registered_channels (successor_nick_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F13B8A6C0BC2966 ON registered_channels (name_lower)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, msg_privmsg BOOLEAN NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_nicks (nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id) SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F375ED32E93 ON registered_nicks (vhost)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE oper_admins');
        $this->addSql('DROP TABLE oper_permissions');
        $this->addSql('DROP TABLE oper_roles');
        $this->addSql('DROP TABLE oper_role_permissions');
        $this->addSql('CREATE TEMPORARY TABLE __temp__channel_access AS SELECT channel_id, nick_id, level, id FROM channel_access');
        $this->addSql('DROP TABLE channel_access');
        $this->addSql('CREATE TABLE channel_access (channel_id INTEGER NOT NULL, nick_id INTEGER NOT NULL, level INTEGER NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO channel_access (channel_id, nick_id, level, id) SELECT channel_id, nick_id, level, id FROM __temp__channel_access');
        $this->addSql('DROP TABLE __temp__channel_access');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_nick ON channel_access (channel_id, nick_id)');
        $this->addSql('CREATE INDEX idx_channel_access_channel ON channel_access (channel_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__channel_levels AS SELECT channel_id, level_key, value, id FROM channel_levels');
        $this->addSql('DROP TABLE channel_levels');
        $this->addSql('CREATE TABLE channel_levels (channel_id INTEGER NOT NULL, level_key VARCHAR(32) NOT NULL, value INTEGER NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO channel_levels (channel_id, level_key, value, id) SELECT channel_id, level_key, value, id FROM __temp__channel_levels');
        $this->addSql('DROP TABLE __temp__channel_levels');
        $this->addSql('CREATE INDEX idx_channel_levels_channel ON channel_levels (channel_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_level_key ON channel_levels (channel_id, level_key)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__memo_ignores AS SELECT target_nick_id, target_channel_id, ignored_nick_id, id FROM memo_ignores');
        $this->addSql('DROP TABLE memo_ignores');
        $this->addSql('CREATE TABLE memo_ignores (target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, ignored_nick_id INTEGER NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO memo_ignores (target_nick_id, target_channel_id, ignored_nick_id, id) SELECT target_nick_id, target_channel_id, ignored_nick_id, id FROM __temp__memo_ignores');
        $this->addSql('DROP TABLE __temp__memo_ignores');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_ignore_target_ignored ON memo_ignores (target_nick_id, target_channel_id, ignored_nick_id)');
        $this->addSql('CREATE INDEX idx_memo_ignores_target_channel ON memo_ignores (target_channel_id)');
        $this->addSql('CREATE INDEX idx_memo_ignores_target_nick ON memo_ignores (target_nick_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__memo_settings AS SELECT target_nick_id, target_channel_id, enabled, id FROM memo_settings');
        $this->addSql('DROP TABLE memo_settings');
        $this->addSql('CREATE TABLE memo_settings (target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, enabled BOOLEAN NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO memo_settings (target_nick_id, target_channel_id, enabled, id) SELECT target_nick_id, target_channel_id, enabled, id FROM __temp__memo_settings');
        $this->addSql('DROP TABLE __temp__memo_settings');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_settings_channel ON memo_settings (target_channel_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_memo_settings_nick ON memo_settings (target_nick_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__memos AS SELECT target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at, id FROM memos');
        $this->addSql('DROP TABLE memos');
        $this->addSql('CREATE TABLE memos (target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, sender_nick_id INTEGER NOT NULL, message CLOB NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO memos (target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at, id) SELECT target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at, id FROM __temp__memos');
        $this->addSql('DROP TABLE __temp__memos');
        $this->addSql('CREATE INDEX idx_memos_sender ON memos (sender_nick_id)');
        $this->addSql('CREATE INDEX idx_memos_target_channel ON memos (target_channel_id)');
        $this->addSql('CREATE INDEX idx_memos_target_nick ON memos (target_nick_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_channels AS SELECT name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id FROM registered_channels');
        $this->addSql('DROP TABLE registered_channels');
        $this->addSql('CREATE TABLE registered_channels (name VARCHAR(64) NOT NULL, name_lower VARCHAR(64) NOT NULL, founder_nick_id INTEGER NOT NULL, successor_nick_id INTEGER DEFAULT NULL, description VARCHAR(255) DEFAULT \'""\' NOT NULL, url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, entrymsg VARCHAR(255) DEFAULT \'""\' NOT NULL, topic_lock BOOLEAN DEFAULT 0 NOT NULL, mlock_active BOOLEAN DEFAULT 0 NOT NULL, mlock VARCHAR(64) DEFAULT \'""\' NOT NULL, mlock_params CLOB DEFAULT \'[]\' NOT NULL, secure BOOLEAN DEFAULT 0 NOT NULL, topic CLOB DEFAULT NULL, last_topic_set_at DATETIME DEFAULT NULL, last_topic_set_by_nick VARCHAR(64) DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_channels (name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id) SELECT name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id FROM __temp__registered_channels');
        $this->addSql('DROP TABLE __temp__registered_channels');
        $this->addSql('CREATE INDEX idx_chanserv_founder ON registered_channels (founder_nick_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_chanserv_name_lower ON registered_channels (name_lower)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, msg_privmsg BOOLEAN DEFAULT 0 NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_nicks (nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id) SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F375ED32E93 ON registered_nicks (vhost)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
    }
}
