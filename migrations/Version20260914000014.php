<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create memo_ignores table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('memo_ignores');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('target_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('target_channel_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('ignored_nick_id', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['target_nick_id', 'target_channel_id', 'ignored_nick_id'], 'uniq_memo_ignore_target_ignored');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('memo_ignores');
    }
}
