<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create oper_ircops table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('oper_ircops');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('nick_id', Types::INTEGER);
        $table->addColumn('role_id', Types::INTEGER);
        $table->addColumn('added_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('added_by_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('reason', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['nick_id'], 'uniq_ircop_nick');
        $table->addIndex(['role_id'], 'idx_ircop_role');
        $table->addForeignKeyConstraint('oper_roles', ['role_id'], ['id'], [], 'FK_533CFD6FD60322AC');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('oper_ircops');
    }
}
