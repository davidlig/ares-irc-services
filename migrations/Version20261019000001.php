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

final class Version20261019000001 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Add the e-mail domain block list used by nickname registration';
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

        $domains = Table::editor()->setUnquotedName('blocked_email_domains');
        $domains->addColumn(
            Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create()
        );
        $domains->addColumn(
            Column::editor()->setUnquotedName('domain')->setTypeName(Types::STRING)->setLength(253)->create()
        );
        $domains->addColumn(
            Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create()
        );
        $domains->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create()
        );
        $domains->addIndex(
            Index::editor()->setUnquotedName('uniq_blocked_email_domain')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['domain'])->create()
        );
        $editor->addTable($domains->create());

        $this->queueDiff($schema, $editor->create());
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $editor->dropTableByUnquotedName('blocked_email_domains');

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
