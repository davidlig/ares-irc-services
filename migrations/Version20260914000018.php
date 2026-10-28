<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create udb_block_states table';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('udb_block_states');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('block')->setTypeName(Types::STRING)->setLength(1)->create());
        $table->addColumn(Column::editor()->setUnquotedName('checksum')->setTypeName(Types::STRING)->setLength(64)->create());
        $table->addColumn(Column::editor()->setUnquotedName('record_count')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create());
        $table->addColumn(Column::editor()->setUnquotedName('modified_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addUniqueConstraint(UniqueConstraint::editor()->setUnquotedName('uniq_udb_block_state_block')->setUnquotedColumnNames(...['block'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('udb_block_states'));
    }
}
