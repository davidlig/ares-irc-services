<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000005 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Create nick_history table';
    }

    public function preUp(Schema $schema): void
    {
        $this->editedSchema = null;
        $schema->getTables();
    }

    public function preDown(Schema $schema): void
    {
        $this->editedSchema = null;
        $schema->getTables();
    }

    public function up(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $table = Table::editor()->setUnquotedName('nick_history');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('nick_id')->setTypeName(Types::INTEGER)->create());
        $table->addColumn(Column::editor()->setUnquotedName('action')->setTypeName(Types::STRING)->setLength(50)->create());
        $table->addColumn(Column::editor()->setUnquotedName('performed_by')->setTypeName(Types::STRING)->setLength(32)->create());
        $table->addColumn(Column::editor()->setUnquotedName('performed_by_nick_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('performed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('message')->setTypeName(Types::STRING)->setLength(512)->create());
        $table->addColumn(Column::editor()->setUnquotedName('extra_data')->setTypeName(Types::JSON)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_nick_id')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['nick_id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_performed_at')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['performed_at'])->create());
        $editor->addTable($table->create());

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $editor->dropTableByUnquotedName('nick_history');

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
