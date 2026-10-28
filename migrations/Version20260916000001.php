<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track Ares-owned service nickname reservations per IRC protocol';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('service_nick_reservations');
        $table->addColumn(Column::editor()->setUnquotedName('protocol')->setTypeName(Types::STRING)->setLength(32)->create());
        $table->addColumn(Column::editor()->setUnquotedName('nickname_lower')->setTypeName(Types::STRING)->setLength(64)->create());
        $table->addColumn(Column::editor()->setUnquotedName('nickname')->setTypeName(Types::STRING)->setLength(64)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['protocol', 'nickname_lower'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('service_nick_reservations'));
    }
}
