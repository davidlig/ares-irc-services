<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_modes column to oper_roles table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('oper_roles');
        $table->addColumn('user_modes', 'text', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('oper_roles');
        $table->dropColumn('user_modes');
    }
}
