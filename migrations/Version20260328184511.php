<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260328184511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make channel_akick.creator_nick_id nullable to support clearing creator on nick drop';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__channel_akick AS SELECT id, channel_id, creator_nick_id, mask, reason, created_at, expires_at FROM channel_akick');
        $this->addSql('DROP TABLE channel_akick');
        $this->addSql('CREATE TABLE channel_akick (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, channel_id INTEGER NOT NULL, creator_nick_id INTEGER DEFAULT NULL, mask VARCHAR(255) NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO channel_akick (id, channel_id, creator_nick_id, mask, reason, created_at, expires_at) SELECT id, channel_id, creator_nick_id, mask, reason, created_at, expires_at FROM __temp__channel_akick');
        $this->addSql('DROP TABLE __temp__channel_akick');
        $this->addSql('CREATE INDEX idx_channel_akick_creator_nick_id ON channel_akick (creator_nick_id)');
        $this->addSql('CREATE INDEX idx_channel_akick_channel_id ON channel_akick (channel_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__channel_akick AS SELECT channel_id, creator_nick_id, mask, reason, created_at, expires_at, id FROM channel_akick');
        $this->addSql('DROP TABLE channel_akick');
        $this->addSql('CREATE TABLE channel_akick (channel_id INTEGER NOT NULL, creator_nick_id INTEGER NOT NULL, mask VARCHAR(255) NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO channel_akick (channel_id, creator_nick_id, mask, reason, created_at, expires_at, id) SELECT channel_id, creator_nick_id, mask, reason, created_at, expires_at, id FROM __temp__channel_akick');
        $this->addSql('DROP TABLE __temp__channel_akick');
        $this->addSql('CREATE INDEX idx_channel_akick_channel_id ON channel_akick (channel_id)');
        $this->addSql('CREATE INDEX idx_channel_akick_creator_nick_id ON channel_akick (creator_nick_id)');
    }
}
