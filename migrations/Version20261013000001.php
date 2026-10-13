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

final class Version20261013000001 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Add the network-wide e-mail verification setting for nickname registration';
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

        $settings = Table::editor()->setUnquotedName('email_verification_settings');
        $settings->addColumn(
            Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create()
        );
        $settings->addColumn(
            Column::editor()->setUnquotedName('verification_required')->setTypeName(Types::BOOLEAN)->create()
        );
        $settings->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create()
        );
        $editor->addTable($settings->create());

        $this->queueDiff($schema, $editor->create());
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $editor->dropTableByUnquotedName('email_verification_settings');

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
