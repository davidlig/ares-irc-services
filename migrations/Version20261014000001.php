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

final class Version20261014000001 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Add the CReG channel registration request queue awaiting manual approval';
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

        $requests = Table::editor()->setUnquotedName('channel_registration_requests');
        $requests->addColumn(
            Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('channel')->setTypeName(Types::STRING)->setLength(64)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('channel_lower')->setTypeName(Types::STRING)->setLength(64)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('requester_account_id')->setTypeName(Types::INTEGER)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('requester_nick')->setTypeName(Types::STRING)->setLength(32)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('description')->setTypeName(Types::STRING)->setLength(255)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('status')->setTypeName(Types::STRING)->setLength(16)->setDefaultValue('pending')->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('approved_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('approved_by_nick')->setTypeName(Types::STRING)->setLength(32)->setNotNull(false)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('denied_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('denied_by_nick')->setTypeName(Types::STRING)->setLength(32)->setNotNull(false)->create()
        );
        $requests->addColumn(
            Column::editor()->setUnquotedName('denied_reason')->setTypeName(Types::TEXT)->setNotNull(false)->create()
        );
        $requests->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create()
        );
        $requests->addIndex(
            Index::editor()->setUnquotedName('uniq_channel_registration_channel')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['channel_lower'])->create()
        );
        $requests->addIndex(
            Index::editor()->setUnquotedName('idx_channel_registration_requester')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['requester_account_id', 'status'])->create()
        );
        $requests->addIndex(
            Index::editor()->setUnquotedName('idx_channel_registration_status')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['status'])->create()
        );
        $editor->addTable($requests->create());

        $this->queueDiff($schema, $editor->create());
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $editor->dropTableByUnquotedName('channel_registration_requests');

        $this->queueDiff($schema, $editor->create());
    }

    /** Planning reads only; all DDL remains queued and dry-run safe. */
    private function queueDiff(Schema $current, Schema $target): void
    {
        $this->editedSchema = clone $target;

        $diff = $this->connection
            ->createSchemaManager()
            ->createComparator()
            ->compareSchemas(clone $current, $target);

        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
