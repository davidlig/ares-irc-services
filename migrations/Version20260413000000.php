<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channel_history table for ChanServ HISTORY command';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE channel_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id INTEGER NOT NULL,
            action VARCHAR(50) NOT NULL,
            performed_by VARCHAR(32) NOT NULL,
            performed_by_nick_id INTEGER DEFAULT NULL,
            performed_at DATETIME NOT NULL,
            message VARCHAR(512) NOT NULL,
            extra_data JSON DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX idx_ch_history_channel_id ON channel_history (channel_id)');
        $this->addSql('CREATE INDEX idx_ch_history_performed_at ON channel_history (performed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE channel_history');
    }
}
