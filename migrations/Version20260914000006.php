<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create forbidden_vhosts table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('forbidden_vhosts');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('pattern', Types::STRING, ['length' => 255]);
        $table->addColumn('created_by_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
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
