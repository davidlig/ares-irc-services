<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
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

        $defaultSchema = '' !== $schema->getName() ? $schema->getName() : $manager->createSchemaConfig()->getName();
        if (null === $defaultSchema && $platform instanceof SQLitePlatform) {
            $defaultSchema = 'main';
        }

        foreach ([
            'registered_nicks' => 'idx_registered_nicks_list_pattern',
            'registered_channels' => 'idx_registered_channels_list_pattern',
        ] as $name => $index) {
            $target = null;
            if ($schema->hasTable($name)) {
                $target = $schema->getTable($name);
            } else {
                foreach ($schema->getTables() as $candidate) {
                    if (!$candidate->getObjectName()->getUnqualifiedName()->equals(Identifier::unquoted($name), $folding)) {
                        continue;
                    }

                    if ($this->isDefaultSchema($candidate->getObjectName()->getQualifier(), $defaultSchema, $folding)) {
                        $target = $candidate;
                        break;
                    }

                    $target ??= $candidate;
                }
            }

            if (null === $target) {
                continue;
            }

            $targetName = $target->getObjectName();
            $targetIsDefault = $this->isDefaultSchema($targetName->getQualifier(), $defaultSchema, $folding);
            $matchedTableName = null;
            $matchedIsDefault = false;

            foreach ($tableNames as $tableName) {
                if (!$tableName->getUnqualifiedName()->equals($targetName->getUnqualifiedName(), $folding)) {
                    continue;
                }

                $tableIsDefault = $this->isDefaultSchema($tableName->getQualifier(), $defaultSchema, $folding);
                if ($targetIsDefault !== $tableIsDefault) {
                    continue;
                }

                if (!$targetIsDefault) {
                    $targetQualifier = $targetName->getQualifier();
                    $tableQualifier = $tableName->getQualifier();
                    if (null === $targetQualifier || null === $tableQualifier || !$targetQualifier->equals($tableQualifier, $folding)) {
                        continue;
                    }
                }

                $matchedTableName = $tableName;
                $matchedIsDefault = $tableIsDefault;
                break;
            }

            if (null === $matchedTableName) {
                continue;
            }

            $isQuoted = ($targetName->getQualifier()?->isQuoted() ?? false)
                || ($matchedTableName->getQualifier()?->isQuoted() ?? false)
                || $targetName->getUnqualifiedName()->isQuoted()
                || $matchedTableName->getUnqualifiedName()->isQuoted();

            $schemaName = $matchedIsDefault ? null : ($matchedTableName->getQualifier()?->getValue() ?? $targetName->getQualifier()?->getValue());
            $tableName = $targetName->getUnqualifiedName()->getValue();

            $actual = $isQuoted
                ? $manager->introspectTableByQuotedName($tableName, $schemaName)
                : $manager->introspectTableByUnquotedName($tableName, $schemaName);
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
            $qualifier = null !== $defaultQualifier ? Identifier::quoted($defaultQualifier) : null;

            $settings = Table::editor()->setName(new OptionallyQualifiedName(Identifier::unquoted('service_settings'), $qualifier));
            $settings->addColumn(Column::editor()->setUnquotedName('service_name')->setTypeName(Types::STRING)->setLength(32)->create());
            $settings->addColumn(Column::editor()->setUnquotedName('setting_name')->setTypeName(Types::STRING)->setLength(64)->create());
            $settings->addColumn(Column::editor()->setUnquotedName('setting_value')->setTypeName(Types::JSON)->create());
            $settings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames('service_name', 'setting_name')->create());
            $editor->addTable($settings->create());
        }

        $event->setSchema($editor->create());
    }

    private function isDefaultSchema(?Identifier $qualifier, ?string $defaultSchema, UnquotedIdentifierFolding $folding): bool
    {
        if (null === $qualifier) {
            return true;
        }

        return null !== $defaultSchema && '' !== $defaultSchema && $qualifier->equals(Identifier::unquoted($defaultSchema), $folding);
    }
}
