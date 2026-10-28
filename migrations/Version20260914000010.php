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

final class Version20260914000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create channel_akick table';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('channel_akick');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('channel_id')->setTypeName(Types::INTEGER)->create());
        $table->addColumn(Column::editor()->setUnquotedName('creator_nick_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('mask')->setTypeName(Types::STRING)->setLength(255)->create());
        $table->addColumn(Column::editor()->setUnquotedName('reason')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_channel_akick_channel_id')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['channel_id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_channel_akick_creator_nick_id')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['creator_nick_id'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('channel_akick'));
    }
}
