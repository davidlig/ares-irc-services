<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channel_history table for ChanServ HISTORY command';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('channel_history');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('channel_id', 'integer');
        $table->addColumn('action', 'string', ['length' => 50]);
        $table->addColumn('performed_by', 'string', ['length' => 32]);
        $table->addColumn('performed_by_nick_id', 'integer', ['notnull' => false]);
        $table->addColumn('performed_at', 'datetime_immutable');
        $table->addColumn('message', 'string', ['length' => 512]);
        $table->addColumn('extra_data', 'json', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['channel_id'], 'idx_ch_history_channel_id');
        $table->addIndex(['performed_at'], 'idx_ch_history_performed_at');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('channel_history');
    }
}
