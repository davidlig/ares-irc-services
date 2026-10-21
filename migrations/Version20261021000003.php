<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021000003 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getDescription(): string
    {
        return 'Persist NickServ pending registration delivery mode';
    }

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function preUp(Schema $schema): void
    {
        $this->editedSchema = null;
    }

    public function preDown(Schema $schema): void
    {
        $this->editedSchema = null;
    }

    public function up(Schema $schema): void
    {
        $target = (clone $schema)->edit()->modifyTableByUnquotedName('registered_nicks', static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('registration_mode')->setTypeName(Types::STRING)->setLength(5)->setDefaultValue('EMAIL')->create());
        })->create();
        $this->queueDiff($schema, $target);
    }

    public function down(Schema $schema): void
    {
        // Native DROP COLUMN preserves migration-owned expression/search indexes.
        $target = (clone $schema)->edit()->modifyTableByUnquotedName('registered_nicks', static function (TableEditor $table): void {
            $table->dropColumnByUnquotedName('registration_mode');
        })->create();
        $this->editedSchema = clone $target;
        $this->addSql('ALTER TABLE registered_nicks DROP COLUMN registration_mode');
    }

    private function queueDiff(Schema $schema, Schema $target): void
    {
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas($schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
