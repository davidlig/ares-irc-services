<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223221403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timezone column to registered_nicks for user timezone preference.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN NOT NULL, vhost VARCHAR(255) DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL)');
        $this->addSql('INSERT INTO registered_nicks (id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost) SELECT id, nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
        $this->addSql('CREATE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F3710949D4C ON registered_nicks (nickname_lower)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E946F37E7927C74 ON registered_nicks (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__registered_nicks AS SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, id FROM registered_nicks');
        $this->addSql('DROP TABLE registered_nicks');
        $this->addSql('CREATE TABLE registered_nicks (nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL, status VARCHAR(20) DEFAULT \'registered\' NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, language VARCHAR(10) DEFAULT \'en\' NOT NULL, registered_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, last_quit_message VARCHAR(512) DEFAULT NULL, private BOOLEAN DEFAULT 0 NOT NULL, vhost VARCHAR(255) DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO registered_nicks (nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, id) SELECT nickname, nickname_lower, status, password_hash, email, language, registered_at, expires_at, reason, last_seen_at, last_quit_message, private, vhost, id FROM __temp__registered_nicks');
        $this->addSql('DROP TABLE __temp__registered_nicks');
        $this->addSql('CREATE INDEX idx_status_expires ON registered_nicks (status, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX idx_registered_nicks_email ON registered_nicks (email)');
        $this->addSql('CREATE UNIQUE INDEX idx_nickname_lower ON registered_nicks (nickname_lower)');
    }
}
