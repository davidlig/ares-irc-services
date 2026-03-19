<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create channel_akick table for ChanServ AKICK feature.
 */
final class Version20260319210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_akick table for AKICK entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE channel_akick (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, channel_id INTEGER NOT NULL, creator_nick_id INTEGER NOT NULL, mask VARCHAR(255) NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_channel_akick_channel_id ON channel_akick (channel_id)');
        $this->addSql('CREATE INDEX idx_channel_akick_creator_nick_id ON channel_akick (creator_nick_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE channel_akick');
    }
}
