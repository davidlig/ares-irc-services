<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_levels table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('channel_levels');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('channel_id', Types::INTEGER);
        $table->addColumn('level_key', Types::STRING, ['length' => 32]);
        $table->addColumn('value', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['channel_id', 'level_key'], 'uniq_channel_key');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('channel_levels');
    }
}
