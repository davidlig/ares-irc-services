<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create nick_history table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('nick_history');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('nick_id', Types::INTEGER);
        $table->addColumn('action', Types::STRING, ['length' => 50]);
        $table->addColumn('performed_by', Types::STRING, ['length' => 32]);
        $table->addColumn('performed_by_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('performed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('message', Types::STRING, ['length' => 512]);
        $table->addColumn('extra_data', Types::JSON, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['nick_id'], 'idx_nick_id');
        $table->addIndex(['performed_at'], 'idx_performed_at');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('nick_history');
    }
}
