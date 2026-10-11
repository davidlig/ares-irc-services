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

final class Version20260914000004 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Create registered_nicks table';
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
        $table = Table::editor()->setUnquotedName('registered_nicks');
        $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $table->addColumn(Column::editor()->setUnquotedName('nickname')->setTypeName(Types::STRING)->setLength(32)->create());
        $table->addColumn(Column::editor()->setUnquotedName('nickname_lower')->setTypeName(Types::STRING)->setLength(32)->create());
        $table->addColumn(Column::editor()->setUnquotedName('status')->setTypeName(Types::STRING)->setLength(20)->create());
        $table->addColumn(Column::editor()->setUnquotedName('password_hash')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('email')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('language')->setTypeName(Types::STRING)->setLength(10)->create());
        $table->addColumn(Column::editor()->setUnquotedName('registered_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('reason')->setTypeName(Types::STRING)->setLength(512)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('suspended_until')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('last_seen_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('last_quit_message')->setTypeName(Types::STRING)->setLength(512)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('last_connect_ip')->setTypeName(Types::STRING)->setLength(45)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('last_connect_host')->setTypeName(Types::STRING)->setLength(256)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('private')->setTypeName(Types::BOOLEAN)->create());
        $table->addColumn(Column::editor()->setUnquotedName('vhost')->setTypeName(Types::STRING)->setLength(48)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('timezone')->setTypeName(Types::STRING)->setLength(64)->setNotNull(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('msg_privmsg')->setTypeName(Types::BOOLEAN)->create());
        $table->addColumn(Column::editor()->setUnquotedName('no_expire')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create());
        $table->addColumn(Column::editor()->setUnquotedName('pending_deletion_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create());
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('UNIQ_2E946F3710949D4C')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['nickname_lower'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('UNIQ_2E946F37E7927C74')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['email'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('UNIQ_2E946F375ED32E93')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['vhost'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_nickname_lower')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['nickname_lower'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_status_expires')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['status', 'expires_at'])->create());
        $table->addIndex(Index::editor()->setUnquotedName('idx_status_pending_deletion')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['status', 'pending_deletion_at'])->create());
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
        $editor->dropTableByUnquotedName('registered_nicks');

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
