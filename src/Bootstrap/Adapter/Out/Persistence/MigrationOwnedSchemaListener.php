<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/** Projects only explicitly migration-owned extensions during read-only schema validation. */
final class MigrationOwnedSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $connection = $event->getEntityManager()->getConnection();
        $manager = $connection->createSchemaManager();
        $tables = $manager->listTableNames();
        $schema = $event->getSchema();
        $editor = $schema->edit();
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        foreach ([
            'registered_nicks' => 'idx_registered_nicks_list_pattern',
            'registered_channels' => 'idx_registered_channels_list_pattern',
        ] as $name => $index) {
            if (!$schema->hasTable($name) || !in_array($name, $tables, true)) {
                continue;
            }
            $actual = $manager->introspectTable($name);
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
        // SQLite introspects an INTEGER PRIMARY KEY as autoincrement even for assigned IDs.
        // Keep the singleton's generator NONE; this changes only its portable schema representation.
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform && $schema->hasTable('nickserv_registration_policy')) {
            $editor->modifyTableByUnquotedName('nickserv_registration_policy', static function (TableEditor $table): void {
                $table->modifyColumnByUnquotedName('id', static function (ColumnEditor $column): void {
                    $column->setAutoincrement(true);
                });
            });
        }
        $event->setSchema($editor->create());
    }
}
