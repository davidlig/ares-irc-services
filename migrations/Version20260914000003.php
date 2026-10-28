<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
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
        $table = Table::editor()->setUnquotedName('oper_ircops');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('nick_id')->setTypeName(Types::INTEGER)->create());
        $table->addColumn(Column::editor()->setUnquotedName('role_id')->setTypeName(Types::INTEGER)->create());
        $table->addColumn(Column::editor()->setUnquotedName('added_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('added_by_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('reason')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('uniq_ircop_nick')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['nick_id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_ircop_role')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['role_id'])->create());
        $table->addForeignKeyConstraint(ForeignKeyConstraint::editor()->setUnquotedName('FK_533CFD6FD60322AC')->setUnquotedReferencingColumnNames(...['role_id'])->setUnquotedReferencedTableName('oper_roles')->setUnquotedReferencedColumnNames(...['id'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->platform instanceof SQLitePlatform) {
            $name = $this->platform instanceof PostgreSQLPlatform ? 'fk_533cfd6fd60322ac' : $this->platform->quoteIdentifier('FK_533CFD6FD60322AC');
            $this->addSql($this->platform->getDropForeignKeySQL($name, 'oper_ircops'));
        }
        $this->addSql($this->platform->getDropTableSQL('oper_ircops'));
    }
}
