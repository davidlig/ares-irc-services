<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create nick_history table for nickname action history tracking';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('nick_history');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('nick_id', 'integer', ['notnull' => true]);
        $table->addColumn('action', 'string', ['length' => 50, 'notnull' => true]);
        $table->addColumn('performed_by', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('performed_by_nick_id', 'integer', ['notnull' => false]);
        $table->addColumn('performed_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('message', 'string', ['length' => 512, 'notnull' => true]);
        $table->addColumn('extra_data', 'json', ['notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['nick_id'], 'idx_nick_id');
        $table->addIndex(['performed_at'], 'idx_performed_at');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('nick_history');
    }
}
