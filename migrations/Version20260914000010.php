<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_akick table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('channel_akick');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('channel_id', Types::INTEGER);
        $table->addColumn('creator_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('mask', Types::STRING, ['length' => 255]);
        $table->addColumn('reason', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['channel_id'], 'idx_channel_akick_channel_id');
        $table->addIndex(['creator_nick_id'], 'idx_channel_akick_creator_nick_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('channel_akick');
    }
}
