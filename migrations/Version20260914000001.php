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

final class Version20260914000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create oper_permissions table';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('oper_permissions');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('name')->setTypeName(Types::STRING)->setLength(64)->create());
        $table->addColumn(Column::editor()->setUnquotedName('description')->setTypeName(Types::STRING)->setLength(255)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('uniq_permission_name')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['name'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('oper_permissions'));
    }
}
