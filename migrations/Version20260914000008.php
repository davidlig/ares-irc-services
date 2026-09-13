<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_access table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('channel_access');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('channel_id', Types::INTEGER);
        $table->addColumn('nick_id', Types::INTEGER);
        $table->addColumn('level', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['channel_id', 'nick_id'], 'uniq_channel_nick');
        $table->addIndex(['nick_id'], 'idx_channel_access_nick_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('channel_access');
    }
}
