<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260329030742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oper_roles ADD COLUMN forced_vhost_pattern VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__oper_roles AS SELECT name, description, protected, user_modes, id FROM oper_roles');
        $this->addSql('DROP TABLE oper_roles');
        $this->addSql('CREATE TABLE oper_roles (name VARCHAR(32) NOT NULL, description VARCHAR(255) NOT NULL, protected BOOLEAN NOT NULL, user_modes CLOB DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO oper_roles (name, description, protected, user_modes, id) SELECT name, description, protected, user_modes, id FROM __temp__oper_roles');
        $this->addSql('DROP TABLE __temp__oper_roles');
        $this->addSql('CREATE UNIQUE INDEX uniq_role_name ON oper_roles (name)');
    }
}
