<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add no_expire column to registered_channels table for ChanServ NOEXPIRE command';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('registered_channels');
        $table->addColumn('no_expire', 'boolean', ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('registered_channels');
        $table->dropColumn('no_expire');
    }
}
