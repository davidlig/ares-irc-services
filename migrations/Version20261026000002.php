<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000002 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Create common runtime service settings and seed registration defaults';
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
        $settings = Table::editor()->setUnquotedName('service_settings');
        $settings->addColumn(Column::editor()->setUnquotedName('service_name')->setTypeName(Types::STRING)->setLength(32)->create());
        $settings->addColumn(Column::editor()->setUnquotedName('setting_name')->setTypeName(Types::STRING)->setLength(64)->create());
        $settings->addColumn(Column::editor()->setUnquotedName('setting_value')->setTypeName(Types::JSON)->create());
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
        $this->addSql("INSERT INTO service_settings (service_name, setting_name, setting_value) VALUES ('chanserv', 'registration_mode', '\"DIRECT\"')");
        $this->addSql("INSERT INTO service_settings (service_name, setting_name, setting_value) VALUES ('nickserv', 'registration_mode', '\"TOKEN\"')");
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
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
        $this->addSql($this->platform->getDropTableSQL('service_settings'));
        foreach ($this->platform->getCreateTableSQL($policyTable) as $sql) {
            $this->addSql($sql);
        }
    }
}
