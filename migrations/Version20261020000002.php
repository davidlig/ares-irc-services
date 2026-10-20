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

final class Version20261020000002 extends AbstractMigration implements EditedSchemaMigration
{
    private const string TABLE = 'geoip_countries';

    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Add the country gate list published as G-lines when the IRC link is established';
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

        $countries = Table::editor()->setUnquotedName(self::TABLE);
        $countries->addColumn(
            Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create()
        );
        $countries->addColumn(
            Column::editor()->setUnquotedName('country_code')->setTypeName(Types::STRING)->setLength(2)->create()
        );
        $countries->addColumn(
            Column::editor()->setUnquotedName('reason')->setTypeName(Types::STRING)->setLength(255)->create()
        );
        $countries->addColumn(
            Column::editor()->setUnquotedName('registered_by_nick')->setTypeName(Types::STRING)->setLength(32)->create()
        );
        $countries->addColumn(
            Column::editor()->setUnquotedName('registered_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create()
        );
        $countries->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create()
        );
        $countries->addIndex(
            Index::editor()->setUnquotedName('uniq_geoip_country_code')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['country_code'])->create()
        );
        $countries->addIndex(
            Index::editor()->setUnquotedName('idx_geoip_registered_at')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['registered_at'])->create()
        );
        $editor->addTable($countries->create());

        $this->queueDiff($schema, $editor->create());
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $editor->dropTableByUnquotedName(self::TABLE);

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
