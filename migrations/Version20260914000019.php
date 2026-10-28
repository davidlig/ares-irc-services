<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create udb_authority_state table';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('udb_authority_state');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('approved')->setTypeName(Types::BOOLEAN)->create());
        $table->addColumn(Column::editor()->setUnquotedName('approved_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('fingerprint')->setTypeName(Types::STRING)->setLength(64)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('protocol_revision')->setTypeName(Types::STRING)->setLength(40)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('INSERT INTO udb_authority_state (approved) VALUES (?)', [false], [Types::BOOLEAN]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('udb_authority_state'));
    }
}
