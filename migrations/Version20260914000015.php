<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create gline table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('gline');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('mask', Types::STRING, ['length' => 255]);
        $table->addColumn('creator_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('reason', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
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
