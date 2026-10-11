<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

use function explode;
use function is_string;
use function preg_match;
use function str_contains;
use function strlen;

final class Version20261011000001 extends AbstractMigration implements EditedSchemaMigration
{
    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Persist UDB typed row kinds and imported dataset checkpoints';
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
        $this->validateExistingValues(false);
        $editor = (clone $schema)->edit();
        $generations = Table::editor()->setUnquotedName('udb_staged_generations');
        $generations->addColumn(Column::editor()->setUnquotedName('generation_id')->setTypeName(Types::STRING)->setLength(32)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('round_id')->setTypeName(Types::STRING)->setLength(20)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('root_sid')->setTypeName(Types::STRING)->setLength(3)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('root_epoch')->setTypeName(Types::STRING)->setLength(16)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('watermark')->setTypeName(Types::STRING)->setLength(20)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::INTEGER)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('promoted')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create());
        $generations->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['generation_id'])->create());
        $editor->addTable($generations->create());
        $transfers = Table::editor()->setUnquotedName('udb_staged_transfers');
        $transfers->addColumn(Column::editor()->setUnquotedName('generation_id')->setTypeName(Types::STRING)->setLength(32)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('block')->setTypeName(Types::STRING)->setLength(1)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('round_id')->setTypeName(Types::STRING)->setLength(20)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('txid')->setTypeName(Types::STRING)->setLength(31)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('digest')->setTypeName(Types::STRING)->setLength(64)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('watermark')->setTypeName(Types::STRING)->setLength(20)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('expected_count')->setTypeName(Types::INTEGER)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('record_count')->setTypeName(Types::INTEGER)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('byte_count')->setTypeName(Types::INTEGER)->create());
        $transfers->addColumn(Column::editor()->setUnquotedName('complete')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create());
        $transfers->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['generation_id', 'block'])->create());
        $editor->addTable($transfers->create());
        $staged = Table::editor()->setUnquotedName('udb_staged_records');
        $staged->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $staged->addColumn(Column::editor()->setUnquotedName('generation_id')->setTypeName(Types::STRING)->setLength(32)->create());
        $staged->addColumn(Column::editor()->setUnquotedName('block')->setTypeName(Types::STRING)->setLength(1)->create());
        $staged->addColumn(Column::editor()->setUnquotedName('path')->setTypeName(Types::STRING)->setLength(8192)->create());
        $staged->addColumn(Column::editor()->setUnquotedName('identity_path')->setTypeName(Types::BINARY)->setLength(8192)->create());
        $staged->addColumn(Column::editor()->setUnquotedName('identity_hash')->setTypeName(Types::BINARY)->setLength(32)->create());
        $staged->addColumn(Column::editor()->setUnquotedName('value')->setTypeName(Types::BINARY)->setLength(4096)->create());
        $staged->addColumn(Column::editor()->setUnquotedName('value_kind')->setTypeName(Types::SMALLINT)->create());
        $staged->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $staged->addIndex(Index::editor()->setUnquotedName('uniq_udb_stage_generation_identity')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['generation_id', 'block', 'identity_hash'])->create());
        $staged->addIndex(Index::editor()->setUnquotedName('idx_udb_stage_generation_block')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['generation_id', 'block', 'id'])->create());
        $editor->addTable($staged->create());
        $tail = Table::editor()->setUnquotedName('udb_staged_mutations');
        $tail->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $tail->addColumn(Column::editor()->setUnquotedName('generation_id')->setTypeName(Types::STRING)->setLength(32)->create());
        $tail->addColumn(Column::editor()->setUnquotedName('sequence')->setTypeName(Types::STRING)->setLength(20)->create());
        $tail->addColumn(Column::editor()->setUnquotedName('block')->setTypeName(Types::STRING)->setLength(1)->create());
        $tail->addColumn(Column::editor()->setUnquotedName('operation')->setTypeName(Types::STRING)->setLength(3)->create());
        $tail->addColumn(Column::editor()->setUnquotedName('path')->setTypeName(Types::STRING)->setLength(8192)->create());
        $tail->addColumn(Column::editor()->setUnquotedName('value')->setTypeName(Types::BINARY)->setLength(4096)->setNotNull(false)->create());
        $tail->addColumn(Column::editor()->setUnquotedName('value_kind')->setTypeName(Types::SMALLINT)->setNotNull(false)->create());
        $tail->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $tail->addIndex(Index::editor()->setUnquotedName('uniq_udb_stage_mutation_sequence')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['generation_id', 'sequence'])->create());
        $tail->addIndex(Index::editor()->setUnquotedName('idx_udb_stage_mutation_order')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['generation_id', 'id'])->create());
        $editor->addTable($tail->create());
        $editor->modifyTableByUnquotedName('udb_records', static function (TableEditor $records): void {
            $records->addColumn(Column::editor()->setUnquotedName('value_kind')->setTypeName(Types::SMALLINT)->setDefaultValue(1)->create());
            $records->modifyColumnByUnquotedName('value', static function (ColumnEditor $value): void {
                $value->setTypeName(Types::BINARY)->setLength(4096)->setFixed(false);
            });
        });
        $editor->modifyTableByUnquotedName('udb_authority_state', static function (TableEditor $authority): void {
            $authority->addColumn(Column::editor()->setUnquotedName('origin_sid')->setTypeName(Types::STRING)->setLength(3)->setNotNull(false)->create());
            $authority->addColumn(Column::editor()->setUnquotedName('origin_epoch')->setTypeName(Types::STRING)->setLength(16)->setNotNull(false)->create());
            $authority->addColumn(Column::editor()->setUnquotedName('origin_watermark')->setTypeName(Types::STRING)->setLength(20)->setNotNull(false)->create());
            $authority->addColumn(Column::editor()->setUnquotedName('dataset_sid')->setTypeName(Types::STRING)->setLength(3)->setNotNull(false)->create());
            $authority->addColumn(Column::editor()->setUnquotedName('dataset_epoch')->setTypeName(Types::STRING)->setLength(16)->setNotNull(false)->create());
            $authority->addColumn(Column::editor()->setUnquotedName('dataset_watermark')->setTypeName(Types::STRING)->setLength(20)->setNotNull(false)->create());
        });

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $comparison = clone $schema;
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql("ALTER TABLE udb_records ALTER COLUMN value TYPE BYTEA USING convert_to(value, 'UTF8')");
            // Compare against the separately queued conversion, not an implicit BYTEA cast.
            $comparison = (clone $schema)->edit()->modifyTableByUnquotedName('udb_records', static function (TableEditor $records): void {
                $records->modifyColumnByUnquotedName('value', static function (ColumnEditor $value): void {
                    $value->setTypeName(Types::BINARY)->setLength(4096)->setFixed(false);
                });
            })->create();
        }
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas($comparison, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }

        // Fresh multi-version dry-runs have a virtual table, not physical rows.
        // Real upgrades always retain preflight and typed backfill.
        if (!$this->connection->createSchemaManager()->tablesExist(['udb_records'])) {
            return;
        }
        $rows = $this->connection->executeQuery('SELECT id, block, path, value FROM udb_records')->iterateAssociative();
        foreach ($rows as $row) {
            $value = $row['value'] ?? null;
            $block = is_string($row['block']) ? UdbBlock::tryFrom($row['block']) : null;
            $components = [];
            $path = $row['path'];
            foreach (is_string($path) ? explode('::', $path) : [] as $encodedComponent) {
                $component = UdbPathCodec::decodeComponent($encodedComponent);
                if (null === $component) {
                    $components = [];
                    break;
                }
                $components[] = $component;
            }
            $typed = null !== $block && is_string($value)
                ? UdbSchema::typedValueFor($block, $components, $value) : null;
            if (null !== $typed && UdbValueKind::String !== $typed->kind) {
                $this->addSql(
                    'UPDATE udb_records SET value_kind = ? WHERE id = ?',
                    [$typed->kind->value, $row['id']],
                    [ParameterType::INTEGER, ParameterType::INTEGER]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->validateExistingValues(true);
        $editor = (clone $schema)->edit();
        $editor->dropTableByUnquotedName('udb_staged_records');
        $editor->dropTableByUnquotedName('udb_staged_mutations');
        $editor->dropTableByUnquotedName('udb_staged_transfers');
        $editor->dropTableByUnquotedName('udb_staged_generations');
        $editor->modifyTableByUnquotedName('udb_authority_state', static function (TableEditor $authority): void {
            $authority->dropColumnByUnquotedName('origin_sid');
            $authority->dropColumnByUnquotedName('origin_epoch');
            $authority->dropColumnByUnquotedName('origin_watermark');
            $authority->dropColumnByUnquotedName('dataset_sid');
            $authority->dropColumnByUnquotedName('dataset_epoch');
            $authority->dropColumnByUnquotedName('dataset_watermark');
        });
        $editor->modifyTableByUnquotedName('udb_records', static function (TableEditor $records): void {
            $records->dropColumnByUnquotedName('value_kind');
            $records->modifyColumnByUnquotedName('value', static function (ColumnEditor $value): void {
                $value->setTypeName(Types::TEXT)->setLength(null)->setFixed(false);
            });
        });
        $target = $editor->create();
        $this->editedSchema = clone $target;
        $comparison = clone $schema;
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql("ALTER TABLE udb_records ALTER COLUMN value TYPE TEXT USING convert_from(value, 'UTF8')");
            $comparison = (clone $schema)->edit()->modifyTableByUnquotedName('udb_records', static function (TableEditor $records): void {
                $records->modifyColumnByUnquotedName('value', static function (ColumnEditor $value): void {
                    $value->setTypeName(Types::TEXT)->setLength(null)->setFixed(false);
                });
            })->create();
        }
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas($comparison, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
        if ($this->platform instanceof SQLitePlatform) {
            // SQLite's rebuild copies storage classes, so explicitly restore TEXT
            // only after the byte-preserving UTF-8/NUL preflight has succeeded.
            $this->addSql('UPDATE udb_records SET value = CAST(value AS TEXT)');
        }
    }

    /** Planning reads only; all DDL/DML remain queued and dry-run safe. */
    private function validateExistingValues(bool $downgrade): void
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['udb_records'])) {
            return;
        }
        foreach ($this->connection->executeQuery('SELECT value FROM udb_records')->iterateAssociative() as $row) {
            $value = Type::getType(Types::BINARY)->convertToPHPValue($row['value'], $this->platform);
            if (!is_string($value)) {
                throw new AbortMigration('Cannot decode a persisted UDB logical value.');
            }
            $this->abortIf(strlen($value) > 4096, 'A persisted UDB logical value exceeds the native limit.');
            $this->abortIf($downgrade && (str_contains($value, "\0") || 1 !== preg_match('//u', $value)), 'Binary UDB logical values cannot be losslessly downgraded to UTF-8 TEXT.');
        }
    }
}
