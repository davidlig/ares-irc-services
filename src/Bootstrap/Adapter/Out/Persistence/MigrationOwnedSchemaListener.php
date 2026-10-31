<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\Identifier;
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
            $target = null;
            if ($schema->hasTable($name)) {
                $target = $schema->getTable($name);
            } else {
                foreach ($schema->getTables() as $candidate) {
                    if ($candidate->getObjectName()->getUnqualifiedName()->equals(Identifier::unquoted($name), $folding)) {
                        $target = $candidate;
                        break;
                    }
                }
            }

            if (null === $target) {
                continue;
            }

            $targetName = $target->getObjectName();
            $targetQualifier = $targetName->getQualifier();
            $matchedTableName = null;

            foreach ($tableNames as $tableName) {
                if ((null === $targetQualifier) !== (null === $tableName->getQualifier())) {
                    continue;
                }

                if ($tableName->equals($targetName, $folding)) {
                    $matchedTableName = $tableName;
                    break;
                }
            }

            if (null === $matchedTableName) {
                continue;
            }

            $schemaName = $matchedTableName->getQualifier()?->getValue();
            $actual = $manager->introspectTableByUnquotedName($targetName->getUnqualifiedName()->getValue(), $schemaName);
            $editor->modifyTable($targetName, static function (TableEditor $table) use ($actual, $target, $index, $mysql): void {
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
            $defaultQualifier = '' !== $schema->getName() ? $schema->getName() : $manager->createSchemaConfig()->getName();

            $settings = Table::editor()->setUnquotedName('service_settings', $defaultQualifier);
            $settings->addColumn(Column::editor()->setUnquotedName('service_name')->setTypeName(Types::STRING)->setLength(32)->create());
            $settings->addColumn(Column::editor()->setUnquotedName('setting_name')->setTypeName(Types::STRING)->setLength(64)->create());
            $settings->addColumn(Column::editor()->setUnquotedName('setting_value')->setTypeName(Types::JSON)->create());
            $settings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('service_name', 'setting_name')->create());
            $editor->addTable($settings->create());
        }

        $event->setSchema($editor->create());
    }
}
