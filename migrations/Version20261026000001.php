<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000001 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Add ChanServ registration approval lifecycle metadata';
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
            $table->addColumn(Column::editor()->setUnquotedName('registration_rejection_reason')->setTypeName(Types::TEXT)->setNotnull(false)->create());
            $table->addColumn(Column::editor()->setUnquotedName('registration_rejected_by_account_id')->setTypeName(Types::INTEGER)->setNotnull(false)->create());
            $table->addColumn(Column::editor()->setUnquotedName('registration_rejected_by_nickname')->setTypeName(Types::STRING)->setLength(64)->setNotnull(false)->create());
            $table->addColumn(Column::editor()->setUnquotedName('registration_rejected_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotnull(false)->create());
            $table->addColumn(Column::editor()->setUnquotedName('registration_restricted_until')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotnull(false)->create());
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
            $table->dropColumnByUnquotedName('registration_rejection_reason');
            $table->dropColumnByUnquotedName('registration_rejected_by_account_id');
            $table->dropColumnByUnquotedName('registration_rejected_by_nickname');
            $table->dropColumnByUnquotedName('registration_rejected_at');
            $table->dropColumnByUnquotedName('registration_restricted_until');
        });
        $target = $editor->create();
        $this->editedSchema = clone $target;
        // Native drops retain SQLite's migration-owned COLLATE NOCASE expression index.
        foreach ([
            'registration_rejection_reason',
            'registration_rejected_by_account_id',
            'registration_rejected_by_nickname',
            'registration_rejected_at',
            'registration_restricted_until',
        ] as $column) {
            $this->addSql('ALTER TABLE registered_channels DROP COLUMN ' . $column);
        }
    }
}
