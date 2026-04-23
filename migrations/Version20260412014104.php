<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412014104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add forbidden_reason column and forbidden status to registered_channels';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('registered_channels');
        $table->addColumn('forbidden_reason', 'string', ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('registered_channels');
        $table->dropColumn('forbidden_reason');
    }
}
