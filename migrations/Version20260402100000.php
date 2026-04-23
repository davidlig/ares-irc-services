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
        $table = $schema->getTable('registered_nicks');
        $table->addColumn('suspended_until', 'datetime_immutable', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('registered_nicks');
        $table->dropColumn('suspended_until');
    }
}
