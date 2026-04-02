<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add suspended_until column to registered_nicks for temporary suspensions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_nicks ADD COLUMN suspended_until DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_nicks DROP COLUMN suspended_until');
    }
}
