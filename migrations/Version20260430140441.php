<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430140441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create motd table for OperServ MOTD command';
    }

    public function up(Schema $schema): void
    {
        $motd = $schema->createTable('motd');
        $motd->addColumn('id', 'integer', ['autoincrement' => true]);
        $motd->addColumn('text', 'string', ['length' => 400]);
        $motd->addColumn('enabled', 'boolean');
        $motd->addColumn('bot_nickname', 'string', ['length' => 128]);
        $motd->addColumn('message_type', 'string', ['length' => 10]);
        $motd->addColumn('creator_nick_id', 'integer', ['notnull' => false]);
        $motd->addColumn('created_at', 'datetime_immutable');
        $motd->addColumn('expires_at', 'datetime_immutable', ['notnull' => false]);
        $motd->setPrimaryKey(['id']);
        $motd->addIndex(['enabled'], 'idx_motd_enabled');
        $motd->addIndex(['creator_nick_id'], 'idx_motd_creator_nick_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('motd');
    }
}
