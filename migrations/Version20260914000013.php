<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create memo_settings table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('memo_settings');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('target_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('target_channel_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('enabled', Types::BOOLEAN);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['target_nick_id'], 'uniq_memo_settings_nick');
        $table->addUniqueIndex(['target_channel_id'], 'uniq_memo_settings_channel');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('memo_settings');
    }
}
