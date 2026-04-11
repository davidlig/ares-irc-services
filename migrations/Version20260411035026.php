<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260411035026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channel suspension columns (status, suspended_reason, suspended_until) to registered_channels';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__forbidden_vhosts AS SELECT id, pattern, created_by_nick_id, created_at FROM forbidden_vhosts');
        $this->addSql('DROP TABLE forbidden_vhosts');
        $this->addSql('CREATE TABLE forbidden_vhosts (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, pattern VARCHAR(255) NOT NULL, created_by_nick_id INTEGER DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO forbidden_vhosts (id, pattern, created_by_nick_id, created_at) SELECT id, pattern, created_by_nick_id, created_at FROM __temp__forbidden_vhosts');
        $this->addSql('DROP TABLE __temp__forbidden_vhosts');
        $this->addSql('CREATE INDEX idx_created_by ON forbidden_vhosts (created_by_nick_id)');
        $this->addSql('CREATE INDEX idx_pattern ON forbidden_vhosts (pattern)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BD15B6D8A3BCFC8E ON forbidden_vhosts (pattern)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__gline AS SELECT id, mask, creator_nick_id, reason, created_at, expires_at FROM gline');
        $this->addSql('DROP TABLE gline');
        $this->addSql('CREATE TABLE gline (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, mask VARCHAR(255) NOT NULL, creator_nick_id INTEGER DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO gline (id, mask, creator_nick_id, reason, created_at, expires_at) SELECT id, mask, creator_nick_id, reason, created_at, expires_at FROM __temp__gline');
        $this->addSql('DROP TABLE __temp__gline');
        $this->addSql('CREATE INDEX idx_gline_creator_nick_id ON gline (creator_nick_id)');
        $this->addSql('CREATE INDEX idx_gline_expires_at ON gline (expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_gline_mask ON gline (mask)');
        $this->addSql('ALTER TABLE registered_channels ADD COLUMN status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE registered_channels ADD COLUMN suspended_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE registered_channels ADD COLUMN suspended_until DATETIME DEFAULT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id, suspended_until, no_expire, last_connect_ip, last_connect_host FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, msg_privmsg BOOLEAN NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, suspended_until DATETIME DEFAULT NULL, no_expire BOOLEAN NOT NULL, last_connect_ip VARCHAR(45) DEFAULT NULL, last_connect_host VARCHAR(256) DEFAULT NULL)');
        $this->addSql('INSERT INTO registered_nicks (nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id, suspended_until, no_expire, last_connect_ip, last_connect_host) SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg, id, suspended_until, no_expire, last_connect_ip, last_connect_host FROM __temp__registered_nicks');
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
        $this->addSql('CREATE TEMPORARY TABLE __temp__forbidden_vhosts AS SELECT pattern, created_by_nick_id, created_at, id FROM forbidden_vhosts');
        $this->addSql('DROP TABLE forbidden_vhosts');
        $this->addSql('CREATE TABLE forbidden_vhosts (pattern VARCHAR(255) NOT NULL, created_by_nick_id INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO forbidden_vhosts (pattern, created_by_nick_id, created_at, id) SELECT pattern, created_by_nick_id, created_at, id FROM __temp__forbidden_vhosts');
        $this->addSql('DROP TABLE __temp__forbidden_vhosts');
        $this->addSql('CREATE INDEX idx_pattern ON forbidden_vhosts (pattern)');
        $this->addSql('CREATE INDEX idx_created_by ON forbidden_vhosts (created_by_nick_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_pattern ON forbidden_vhosts (pattern)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__gline AS SELECT mask, creator_nick_id, reason, created_at, expires_at, id FROM gline');
        $this->addSql('DROP TABLE gline');
        $this->addSql('CREATE TABLE gline (mask VARCHAR(255) NOT NULL, creator_nick_id INTEGER DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO gline (mask, creator_nick_id, reason, created_at, expires_at, id) SELECT mask, creator_nick_id, reason, created_at, expires_at, id FROM __temp__gline');
        $this->addSql('DROP TABLE __temp__gline');
        $this->addSql('CREATE INDEX idx_gline_expires_at ON gline (expires_at)');
        $this->addSql('CREATE INDEX idx_gline_creator_nick_id ON gline (creator_nick_id)');
        $this->addSql('CREATE UNIQUE INDEX idx_gline_mask ON gline (mask)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_channels AS SELECT name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id FROM registered_channels');
        $this->addSql('DROP TABLE registered_channels');
        $this->addSql('CREATE TABLE registered_channels (name VARCHAR(64) NOT NULL, name_lower VARCHAR(64) NOT NULL, founder_nick_id INTEGER NOT NULL, successor_nick_id INTEGER DEFAULT NULL, description VARCHAR(255) NOT NULL, url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, entrymsg VARCHAR(255) NOT NULL, topic_lock BOOLEAN NOT NULL, mlock_active BOOLEAN NOT NULL, mlock VARCHAR(64) NOT NULL, mlock_params CLOB NOT NULL, secure BOOLEAN NOT NULL, topic CLOB DEFAULT NULL, last_topic_set_at DATETIME DEFAULT NULL, last_topic_set_by_nick VARCHAR(64) DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_channels (name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id) SELECT name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, id FROM __temp__registered_channels');
        $this->addSql('DROP TABLE __temp__registered_channels');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9F13B8A6C0BC2966 ON registered_channels (name_lower)');
        $this->addSql('CREATE INDEX idx_name_lower ON registered_channels (name_lower)');
        $this->addSql('CREATE INDEX idx_registered_channels_successor_nick_id ON registered_channels (successor_nick_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, suspended_until, last_seen_at, last_quit_message, last_connect_ip, last_connect_host, private, vhost, timezone, msg_privmsg, no_expire, id FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, suspended_until DATETIME DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, last_connect_ip VARCHAR(45) DEFAULT NULL, last_connect_host VARCHAR(256) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, msg_privmsg BOOLEAN NOT NULL, no_expire BOOLEAN DEFAULT 0 NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_nicks (nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, suspended_until, last_seen_at, last_quit_message, last_connect_ip, last_connect_host, private, vhost, timezone, msg_privmsg, no_expire, id) SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, suspended_until, last_seen_at, last_quit_message, last_connect_ip, last_connect_host, private, vhost, timezone, msg_privmsg, no_expire, id FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F375ED32E93 ON registered_nicks (vhost)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
    }
}
