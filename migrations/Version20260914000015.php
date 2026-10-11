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

final class Version20260914000015 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Create gline table';
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
        $table = Table::editor()->setUnquotedName('gline');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('mask')->setTypeName(Types::STRING)->setLength(255)->create());
        $table->addColumn(Column::editor()->setUnquotedName('creator_nick_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('reason')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('uniq_gline_mask')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['mask'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_gline_expires_at')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['expires_at'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_gline_creator_nick_id')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['creator_nick_id'])->create());
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
        $editor->dropTableByUnquotedName('gline');

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
