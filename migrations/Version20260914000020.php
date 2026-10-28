<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger_messages table';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('messenger_messages');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('body')->setTypeName(Types::TEXT)->create());
        $table->addColumn(Column::editor()->setUnquotedName('headers')->setTypeName(Types::TEXT)->create());
        $table->addColumn(Column::editor()->setUnquotedName('queue_name')->setTypeName(Types::STRING)->setLength(190)->create());
        $table->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('available_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('delivered_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['queue_name', 'available_at', 'delivered_at', 'id'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('messenger_messages'));
    }
}
