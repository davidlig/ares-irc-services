<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404231051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create forbidden_vhosts table for NickServ FORBIDVHOST command';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('forbidden_vhosts');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('pattern', 'string', ['length' => 255]);
        $table->addColumn('created_by_nick_id', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['pattern'], 'UNIQ_pattern');
        $table->addIndex(['pattern'], 'idx_pattern');
        $table->addIndex(['created_by_nick_id'], 'idx_created_by');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('forbidden_vhosts');
    }
}
