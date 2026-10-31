<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/** Projects only explicitly migration-owned extensions during read-only schema validation. */
final class MigrationOwnedSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $connection = $event->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();
        $folding = $platform->getUnquotedIdentifierFolding();
        $manager = $connection->createSchemaManager();
        $tableNames = $manager->introspectTableNames();
        $schema = $event->getSchema();
        $editor = $schema->edit();
        $mysql = $platform instanceof AbstractMySQLPlatform;

        foreach ([
            'registered_nicks' => 'idx_registered_nicks_list_pattern',
            'registered_channels' => 'idx_registered_channels_list_pattern',
        ] as $name => $index) {
            if (!$schema->hasTable($name)) {
                continue;
            }

            $targetName = OptionallyQualifiedName::unquoted($name);
            $matchedTableName = null;
            foreach ($tableNames as $tableName) {
                if ($tableName->getUnqualifiedName()->equals($targetName->getUnqualifiedName(), $folding)) {
                    $matchedTableName = $tableName;
                    break;
                }
            }

            if (null === $matchedTableName) {
                continue;
            }

            $schemaName = $matchedTableName->getQualifier()?->getValue();
            $actual = $manager->introspectTableByUnquotedName($name, $schemaName);
            $target = $schema->getTable($name);
            $editor->modifyTableByUnquotedName($name, static function (TableEditor $table) use ($actual, $target, $index, $mysql): void {
                if ($mysql && $actual->hasColumn('list_search_key') && !$target->hasColumn('list_search_key')) {
                    $table->addColumn($actual->getColumn('list_search_key'));
                }
                if ($actual->hasIndex($index) && !$target->hasIndex($index)) {
                    $table->addIndex($actual->getIndex($index));
                }
            });
        }

        // DBAL-only runtime settings still need a canonical schema for drift detection.
        if (!$schema->hasTable('service_settings')) {
            $settings = Table::editor()->setUnquotedName('service_settings');
            $settings->addColumn(Column::editor()->setUnquotedName('service_name')->setTypeName(Types::STRING)->setLength(32)->create());
            $settings->addColumn(Column::editor()->setUnquotedName('setting_name')->setTypeName(Types::STRING)->setLength(64)->create());
            $settings->addColumn(Column::editor()->setUnquotedName('setting_value')->setTypeName(Types::JSON)->create());
            $settings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('service_name', 'setting_name')->create());
            $editor->addTable($settings->create());
        }

        $event->setSchema($editor->create());
    }
}
