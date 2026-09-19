<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create oper_permissions table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('oper_permissions');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 64]);
        $table->addColumn('description', Types::STRING, ['length' => 255]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name'], 'uniq_permission_name');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('oper_permissions');
    }
}
