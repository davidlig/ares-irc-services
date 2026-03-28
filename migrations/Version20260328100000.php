<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add user_modes column to oper_roles table.
 */
final class Version20260328100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_modes column to oper_roles table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oper_roles ADD COLUMN user_modes CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oper_roles DROP COLUMN user_modes');
    }
}
