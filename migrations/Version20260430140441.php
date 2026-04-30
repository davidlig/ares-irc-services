<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430140441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create motd table for OperServ MOTD command';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE motd (text VARCHAR(400) NOT NULL, enabled TINYINT NOT NULL, bot_nickname VARCHAR(128) NOT NULL, message_type VARCHAR(10) NOT NULL, creator_nick_id INT DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, INDEX idx_motd_enabled (enabled), INDEX idx_motd_creator_nick_id (creator_nick_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE motd');
    }
}
