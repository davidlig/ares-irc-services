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

final class Version20260914000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create forbidden_vhosts table';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('forbidden_vhosts');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('pattern')->setTypeName(Types::STRING)->setLength(255)->create());
        $table->addColumn(Column::editor()->setUnquotedName('created_by_nick_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('UNIQ_pattern')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['pattern'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_pattern')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['pattern'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_created_by')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['created_by_nick_id'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('forbidden_vhosts'));
    }
}
