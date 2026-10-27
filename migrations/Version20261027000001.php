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

final class Version20261027000001 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getDescription(): string
    {
        return 'Remove redundant ChanServ registration audit storage; rollback restores schema only';
    }

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
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
        $editor->dropTableByUnquotedName('channel_registration_audit');
        $this->editedSchema = $editor->create();
        $this->addSql($this->platform->getDropTableSQL('channel_registration_audit'));
    }

    public function down(Schema $schema): void
    {
        // Recreate the historical schema for old code; deleted records require a backup.
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
        $audit->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('id')->create());
        $table = $audit->create();
        $editor = (clone $schema)->edit();
        $editor->addTable($table);
        $this->editedSchema = $editor->create();
        foreach ($this->platform->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }
    }
}
