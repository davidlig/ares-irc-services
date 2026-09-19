<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index registered channels for ordered keyset scans';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('registered_channels')->addIndex(['name', 'id'], 'idx_registered_channels_name_id');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('registered_channels')->dropIndex('idx_registered_channels_name_id');
    }
}
