<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds vhost column to registered_nicks for custom virtual host (SET VHOST).
 */
final class Version20260222000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vhost column to registered_nicks (VARCHAR 255 NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_nicks ADD COLUMN vhost VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite does not support DROP COLUMN; would require table recreation.
        $this->addSql('-- SQLite: vhost column left in place (no DROP COLUMN support)');
    }
}
