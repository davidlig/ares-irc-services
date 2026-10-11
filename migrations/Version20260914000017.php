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
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000017 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Create udb_records table with binary identity storage';
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
        $editor->dropTableByUnquotedName('udb_records');

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
