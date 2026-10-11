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

final class Version20260914000007 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Create registered_channels table';
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
        $table = Table::editor()->setUnquotedName('registered_channels');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('name')->setTypeName(Types::STRING)->setLength(64)->create());
        $table->addColumn(Column::editor()->setUnquotedName('name_lower')->setTypeName(Types::STRING)->setLength(64)->create());
        $table->addColumn(Column::editor()->setUnquotedName('founder_nick_id')->setTypeName(Types::INTEGER)->create());
        $table->addColumn(Column::editor()->setUnquotedName('successor_nick_id')->setTypeName(Types::INTEGER)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('description')->setTypeName(Types::STRING)->setLength(255)->create());
        $table->addColumn(Column::editor()->setUnquotedName('url')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('email')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('entrymsg')->setTypeName(Types::STRING)->setLength(255)->create());
        $table->addColumn(Column::editor()->setUnquotedName('topic_lock')->setTypeName(Types::BOOLEAN)->create());
        $table->addColumn(Column::editor()->setUnquotedName('mlock_active')->setTypeName(Types::BOOLEAN)->create());
        $table->addColumn(Column::editor()->setUnquotedName('mlock')->setTypeName(Types::STRING)->setLength(64)->create());
        $table->addColumn(Column::editor()->setUnquotedName('mlock_params')->setTypeName(Types::JSON)->create());
        $table->addColumn(Column::editor()->setUnquotedName('secure')->setTypeName(Types::BOOLEAN)->create());
        $table->addColumn(Column::editor()->setUnquotedName('topic')->setTypeName(Types::TEXT)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('last_topic_set_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('last_topic_set_by_nick')->setTypeName(Types::STRING)->setLength(64)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('last_used_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $table->addColumn(Column::editor()->setUnquotedName('status')->setTypeName(Types::STRING)->setLength(20)->setDefaultValue('active')->create());
        $table->addColumn(Column::editor()->setUnquotedName('suspended_reason')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('suspended_until')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('forbidden_reason')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('no_expire')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('pending_deletion_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('UNIQ_9F13B8A6C0BC2966')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['name_lower'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_name_lower')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['name_lower'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_registered_channels_successor_nick_id')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['successor_nick_id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_registered_channels_pending_deletion')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['status', 'pending_deletion_at'])->create());
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
        $editor->dropTableByUnquotedName('registered_channels');

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
