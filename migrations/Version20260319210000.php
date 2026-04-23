<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_akick table for AKICK entries';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('channel_akick');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('channel_id', 'integer');
        $table->addColumn('creator_nick_id', 'integer');
        $table->addColumn('mask', 'string', ['length' => 255]);
        $table->addColumn('reason', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('expires_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['channel_id'], 'idx_channel_akick_channel_id');
        $table->addIndex(['creator_nick_id'], 'idx_channel_akick_creator_nick_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('channel_akick');
    }
}
