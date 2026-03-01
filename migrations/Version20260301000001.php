<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add msg_privmsg to registered_nicks (SET MSG ON|OFF: PRIVMSG vs NOTICE).
 */
final class Version20260301000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add msg_privmsg column to registered_nicks for NOTICE vs PRIVMSG preference.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, msg_privmsg BOOLEAN NOT NULL DEFAULT 0)');
        $this->addSql('INSERT INTO registered_nicks (id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, msg_privmsg) SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, 0 FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F375ED32E93 ON registered_nicks (vhost)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL)');
        $this->addSql('INSERT INTO registered_nicks (id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone) SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F375ED32E93 ON registered_nicks (vhost)');
    }
}
