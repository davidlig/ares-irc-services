<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919000002 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Index registered channels for filtered maintenance scans';
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
        $editor->modifyTableByUnquotedName('registered_channels', static function (TableEditor $table): void {
            $table->addIndex(Index::editor()->setUnquotedName('idx_registered_channels_status_suspended_until')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['status', 'suspended_until'])->create());
            $table->addIndex(Index::editor()->setUnquotedName('idx_registered_channels_inactivity')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['no_expire', 'last_used_at', 'created_at'])->create());
        });

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
        $editor->modifyTableByUnquotedName('registered_channels', static function (TableEditor $table): void {
            $table->dropIndexByUnquotedName('idx_registered_channels_status_suspended_until');
            $table->dropIndexByUnquotedName('idx_registered_channels_inactivity');
        });

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
