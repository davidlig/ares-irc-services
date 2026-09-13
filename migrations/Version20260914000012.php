<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create memos table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('memos');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('target_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('target_channel_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('sender_nick_id', Types::INTEGER);
        $table->addColumn('message', Types::TEXT);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('read_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['target_nick_id'], 'idx_memos_target_nick');
        $table->addIndex(['target_channel_id'], 'idx_memos_target_channel');
        $table->addIndex(['sender_nick_id'], 'idx_memos_sender');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('memos');
    }
}
