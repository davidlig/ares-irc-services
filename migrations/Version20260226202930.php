<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226202930 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vhost column: length 48, unique constraint (one vhost per account).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(48) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL)');
        $this->addSql('INSERT INTO registered_nicks (id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone) SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F375ED32E93 ON registered_nicks (vhost)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, id FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(255) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_nicks (nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, id) SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, timezone, id FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
    }
}
