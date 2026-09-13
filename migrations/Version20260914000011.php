<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_history table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('channel_history');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('channel_id', Types::INTEGER);
        $table->addColumn('action', Types::STRING, ['length' => 50]);
        $table->addColumn('performed_by', Types::STRING, ['length' => 32]);
        $table->addColumn('performed_by_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('performed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('message', Types::STRING, ['length' => 512]);
        $table->addColumn('extra_data', Types::JSON, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['channel_id'], 'idx_ch_history_channel_id');
        $table->addIndex(['performed_at'], 'idx_ch_history_performed_at');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('channel_history');
    }
}
