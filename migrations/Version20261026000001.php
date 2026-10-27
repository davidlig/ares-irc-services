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
        return 'Add ChanServ approval states and common command-backed service settings';
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
        $settings = Table::editor()->setUnquotedName('service_settings');
        $settings->addColumn(Column::editor()->setUnquotedName('service_name')->setTypeName(Types::STRING)->setLength(32)->create());
        $settings->addColumn(Column::editor()->setUnquotedName('setting_name')->setTypeName(Types::STRING)->setLength(64)->create());
        $settings->addColumn(Column::editor()->setUnquotedName('setting_value')->setTypeName(Types::TEXT)->create());
        $settings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('service_name', 'setting_name')->create());
        $editor->addTable($settings->create());
        // Explicit reset: do not copy the legacy NickServ mode into the new store.
        $editor->dropTableByUnquotedName('nickserv_registration_policy');

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql("INSERT INTO service_settings (service_name, setting_name, setting_value) VALUES ('chanserv', 'registration_mode', 'DIRECT')");
        $this->addSql("INSERT INTO service_settings (service_name, setting_name, setting_value) VALUES ('nickserv', 'registration_mode', 'TOKEN')");
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
        $editor->dropTableByUnquotedName('service_settings');
        // Restore the historical empty table; the discarded mode is not recoverable.
        $policy = Table::editor()->setUnquotedName('nickserv_registration_policy');
        $policy->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create());
        $policy->addColumn(Column::editor()->setUnquotedName('mode')->setTypeName(Types::STRING)->setLength(5)->create());
        $policy->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('id')->create());
        $policyTable = $policy->create();
        $editor->addTable($policyTable);
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
        $this->addSql($this->platform->getDropTableSQL('service_settings'));
        foreach ($this->platform->getCreateTableSQL($policyTable) as $sql) {
            $this->addSql($sql);
        }
    }
}
