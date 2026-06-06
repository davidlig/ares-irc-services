<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recoverable manual DROP state for registered nicks and channels';
    }

    public function up(Schema $schema): void
    {
        $nicks = $schema->getTable('registered_nicks');
        $nicks->addColumn('pending_deletion_at', 'datetime_immutable', ['notnull' => false]);
        $nicks->addIndex(['status', 'pending_deletion_at'], 'idx_status_pending_deletion');

        $channels = $schema->getTable('registered_channels');
        $channels->addColumn('pending_deletion_at', 'datetime_immutable', ['notnull' => false]);
        $channels->addIndex(['status', 'pending_deletion_at'], 'idx_registered_channels_pending_deletion');
    }

    public function down(Schema $schema): void
    {
        $nicks = $schema->getTable('registered_nicks');
        $nicks->dropIndex('idx_status_pending_deletion');
        $nicks->dropColumn('pending_deletion_at');

        $channels = $schema->getTable('registered_channels');
        $channels->dropIndex('idx_registered_channels_pending_deletion');
        $channels->dropColumn('pending_deletion_at');
    }
}
