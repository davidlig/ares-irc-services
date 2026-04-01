<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create gline table for OperServ GLINE feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gline (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, mask VARCHAR(255) NOT NULL, creator_nick_id INTEGER DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX idx_gline_mask ON gline (mask)');
        $this->addSql('CREATE INDEX idx_gline_expires_at ON gline (expires_at)');
        $this->addSql('CREATE INDEX idx_gline_creator_nick_id ON gline (creator_nick_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gline');
    }
}
