<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create udb_records table with binary identity storage';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('udb_records');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('block')->setTypeName(Types::STRING)->setLength(1)->create());
        $table->addColumn(Column::editor()->setUnquotedName('path')->setTypeName(Types::STRING)->setLength(8192)->create());
        $table->addColumn(Column::editor()->setUnquotedName('identity_path')->setTypeName(Types::BINARY)->setLength(8192)->create());
        $table->addColumn(Column::editor()->setUnquotedName('identity_hash')->setTypeName(Types::BINARY)->setLength(32)->create());
        $table->addColumn(Column::editor()->setUnquotedName('value')->setTypeName(Types::TEXT)->create());
        $table->addColumn(Column::editor()->setUnquotedName('updated_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addUniqueConstraint(UniqueConstraint::editor()->setUnquotedName('uniq_udb_record_block_identity')->setUnquotedColumnNames(...['block', 'identity_hash'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_udb_record_block')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['block'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('udb_records'));
    }
}
