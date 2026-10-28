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

final class Version20260914000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_history table';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('channel_history');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('channel_id')->setTypeName(Types::INTEGER)->create());
        $table->addColumn(Column::editor()->setUnquotedName('action')->setTypeName(Types::STRING)->setLength(50)->create());
        $table->addColumn(Column::editor()->setUnquotedName('performed_by')->setTypeName(Types::STRING)->setLength(32)->create());
        $table->addColumn(Column::editor()->setUnquotedName('performed_by_nick_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('performed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('message')->setTypeName(Types::STRING)->setLength(512)->create());
        $table->addColumn(Column::editor()->setUnquotedName('extra_data')->setTypeName(Types::JSON)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_ch_history_channel_id')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['channel_id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_ch_history_performed_at')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['performed_at'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('channel_history'));
    }
}
