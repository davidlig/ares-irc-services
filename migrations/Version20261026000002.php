<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create common runtime service settings and seed registration defaults';
    }

    public function up(Schema $schema): void
    {
        $settings = Table::editor()->setUnquotedName('service_settings');
        $settings->addColumn(Column::editor()->setUnquotedName('service_name')->setTypeName(Types::STRING)->setLength(32)->create());
        $settings->addColumn(Column::editor()->setUnquotedName('setting_name')->setTypeName(Types::STRING)->setLength(64)->create());
        $settings->addColumn(Column::editor()->setUnquotedName('setting_value')->setTypeName(Types::JSON)->create());
        $settings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('service_name', 'setting_name')->create());
        foreach ($this->platform->getCreateTablesSQL([$settings->create()]) as $sql) {
            $this->addSql($sql);
        }
        // Explicit reset: do not copy the legacy NickServ mode into the new store.
        $this->addSql($this->platform->getDropTableSQL('nickserv_registration_policy'));
        $this->addSql("INSERT INTO service_settings (service_name, setting_name, setting_value) VALUES ('chanserv', 'registration_mode', '\"DIRECT\"')");
        $this->addSql("INSERT INTO service_settings (service_name, setting_name, setting_value) VALUES ('nickserv', 'registration_mode', '\"TOKEN\"')");
    }

    public function down(Schema $schema): void
    {
        // Restore the historical empty table; the discarded mode is not recoverable.
        $policy = Table::editor()->setUnquotedName('nickserv_registration_policy');
        $policy->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create());
        $policy->addColumn(Column::editor()->setUnquotedName('mode')->setTypeName(Types::STRING)->setLength(5)->create());
        $policy->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('id')->create());
        $policyTable = $policy->create();
        $this->addSql($this->platform->getDropTableSQL('service_settings'));
        foreach ($this->platform->getCreateTableSQL($policyTable) as $sql) {
            $this->addSql($sql);
        }
    }
}
