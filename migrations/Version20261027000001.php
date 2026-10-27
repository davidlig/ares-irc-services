<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

use function in_array;

final class Version20261027000001 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Allow memos to snapshot a service nickname as their sender';
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
        $editor->modifyTableByUnquotedName('memos', static function ($memos): void {
            $memos->modifyColumnByUnquotedName('sender_nick_id', static function (ColumnEditor $column): void {
                $column->setNotNull(false);
            });
            $memos->addColumn(Column::editor()->setUnquotedName('sender_service_name')->setTypeName(Types::STRING)->setLength(64)->setNotNull(false)->create());
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
        // A fresh-install dry run propagates an edited schema without creating physical tables.
        if ($this->connection->createSchemaManager()->tablesExist(['memos'])
            && !in_array($this->connection->fetchOne('SELECT COUNT(*) FROM memos WHERE sender_service_name IS NOT NULL'), [false, 0, '0'], true)) {
            throw new RuntimeException('Cannot remove service memo sender support while service-authored memos exist.');
        }

        $editor = (clone $schema)->edit();
        $editor->modifyTableByUnquotedName('memos', static function ($memos): void {
            $memos->dropColumnByUnquotedName('sender_service_name');
            $memos->modifyColumnByUnquotedName('sender_nick_id', static function (ColumnEditor $column): void {
                $column->setNotNull(true);
            });
        });
        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
