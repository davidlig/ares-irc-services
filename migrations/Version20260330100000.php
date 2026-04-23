<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create gline table for OperServ GLINE feature';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('gline');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('mask', 'string', ['length' => 255]);
        $table->addColumn('creator_nick_id', 'integer', ['notnull' => false]);
        $table->addColumn('reason', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('expires_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['mask'], 'uniq_gline_mask');
        $table->addIndex(['expires_at'], 'idx_gline_expires_at');
        $table->addIndex(['creator_nick_id'], 'idx_gline_creator_nick_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('gline');
    }
}
