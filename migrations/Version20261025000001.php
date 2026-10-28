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

final class Version20261025000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the OperServ blocked email domain list';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('operserv_badmail');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('domain')->setTypeName(Types::STRING)->setLength(253)->create());
        $table->addColumn(Column::editor()->setUnquotedName('reason')->setTypeName(Types::TEXT)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('uniq_operserv_badmail_domain')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['domain'])->create());

        foreach ($this->platform->getCreateTablesSQL([$table->create()]) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('operserv_badmail'));
    }
}
