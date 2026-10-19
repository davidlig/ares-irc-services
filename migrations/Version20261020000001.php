<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020000001 extends AbstractMigration implements EditedSchemaMigration
{
    private const string TABLE = 'channel_akick';

    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Track how often each AKICK entry caught a member and when it was used last';
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
        $editor->modifyTableByUnquotedName(self::TABLE, static function (TableEditor $table): void {
            $table->addColumn(
                Column::editor()->setUnquotedName('uses')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create()
            );
            $table->addColumn(
                Column::editor()->setUnquotedName('last_used_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create()
            );
        });

        $this->queueDiff($schema, $editor->create());
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $editor->modifyTableByUnquotedName(self::TABLE, static function (TableEditor $table): void {
            $table->dropColumnByUnquotedName('uses');
            $table->dropColumnByUnquotedName('last_used_at');
        });

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
