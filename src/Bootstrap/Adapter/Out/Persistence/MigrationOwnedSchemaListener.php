<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

use function in_array;

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
