<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create motd table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('motd');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('text', Types::STRING, ['length' => 400]);
        $table->addColumn('enabled', Types::BOOLEAN);
        $table->addColumn('bot_nickname', Types::STRING, ['length' => 128]);
        $table->addColumn('message_type', Types::STRING, ['length' => 10]);
        $table->addColumn('creator_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('shown_count', Types::INTEGER, ['default' => 0]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['enabled'], 'idx_motd_enabled');
        $table->addIndex(['creator_nick_id'], 'idx_motd_creator_nick_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('motd');
    }
}
