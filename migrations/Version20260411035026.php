<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260411035026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channel suspension columns (status, suspended_reason, suspended_until) to registered_channels';
    }

    public function up(Schema $schema): void
    {
        $channels = $schema->getTable('registered_channels');
        $channels->addColumn('status', 'string', ['length' => 20, 'default' => 'active']);
        $channels->addColumn('suspended_reason', 'string', ['length' => 255, 'notnull' => false]);
        $channels->addColumn('suspended_until', 'datetime_immutable', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $channels = $schema->getTable('registered_channels');
        $channels->dropColumn('suspended_until');
        $channels->dropColumn('suspended_reason');
        $channels->dropColumn('status');
    }
}
