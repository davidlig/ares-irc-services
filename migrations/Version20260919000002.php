<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index registered channels for filtered maintenance scans';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('registered_channels');
        $table->addIndex(['status', 'suspended_until'], 'idx_registered_channels_status_suspended_until');
        $table->addIndex(['no_expire', 'last_used_at', 'created_at'], 'idx_registered_channels_inactivity');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('registered_channels');
        $table->dropIndex('idx_registered_channels_status_suspended_until');
        $table->dropIndex('idx_registered_channels_inactivity');
    }
}
