<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
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
        return 'Add ChanServ registration approval states and mode policy';
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
        $policy = Table::editor()->setUnquotedName('chanserv_registration_policy');
        $policy->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create());
        $policy->addColumn(Column::editor()->setUnquotedName('mode')->setTypeName(Types::STRING)->setLength(8)->create());
        $policy->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $editor->addTable($policy->create());

        $audit = Table::editor()->setUnquotedName('channel_registration_audit');
        $audit->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('channel_name')->setTypeName(Types::STRING)->setLength(64)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('requester_account_id')->setTypeName(Types::INTEGER)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('operator_account_id')->setTypeName(Types::INTEGER)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('operator_nickname')->setTypeName(Types::STRING)->setLength(64)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('accepted')->setTypeName(Types::BOOLEAN)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('rejection_reason')->setTypeName(Types::TEXT)->setNotnull(false)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('restricted_until')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotnull(false)->create());
        $audit->addColumn(Column::editor()->setUnquotedName('occurred_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create());
        $audit->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $editor->addTable($audit->create());

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
        $editor->dropTableByUnquotedName('chanserv_registration_policy');
        $editor->dropTableByUnquotedName('channel_registration_audit');
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
        $this->addSql($this->platform->getDropTableSQL('chanserv_registration_policy'));
        $this->addSql($this->platform->getDropTableSQL('channel_registration_audit'));
    }
}
