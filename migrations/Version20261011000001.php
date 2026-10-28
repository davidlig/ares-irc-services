<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

use function explode;
use function is_string;
use function preg_match;
use function str_contains;
use function strlen;

final class Version20261011000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist UDB typed row kinds and imported dataset checkpoints';
    }

    public function preUp(Schema $schema): void
    {
        $this->validateExistingValues(false);
    }

    public function preDown(Schema $schema): void
    {
        $this->validateExistingValues(true);
    }

    public function up(Schema $schema): void
    {
        $generations = Table::editor()->setUnquotedName('udb_staged_generations');
        $generations->addColumn(Column::editor()->setUnquotedName('generation_id')->setTypeName(Types::STRING)->setLength(32)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('round_id')->setTypeName(Types::STRING)->setLength(20)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('root_sid')->setTypeName(Types::STRING)->setLength(3)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('root_epoch')->setTypeName(Types::STRING)->setLength(16)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('watermark')->setTypeName(Types::STRING)->setLength(20)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::INTEGER)->create());
        $generations->addColumn(Column::editor()->setUnquotedName('promoted')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create());
        $generations->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['generation_id'])->create());
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

        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql("ALTER TABLE udb_records ALTER COLUMN value TYPE BYTEA USING convert_to(value, 'UTF8')");
        }
        foreach ($this->platform->getCreateTablesSQL([$generations->create(), $transfers->create(), $staged->create(), $tail->create()]) as $sql) {
            $this->addSql($sql);
        }
        $statements = match (true) {
            $this->platform instanceof SQLitePlatform => [
                'ALTER TABLE udb_authority_state ADD COLUMN origin_sid VARCHAR(3) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD COLUMN origin_epoch VARCHAR(16) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD COLUMN origin_watermark VARCHAR(20) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD COLUMN dataset_sid VARCHAR(3) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD COLUMN dataset_epoch VARCHAR(16) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD COLUMN dataset_watermark VARCHAR(20) DEFAULT NULL',
                'CREATE TEMPORARY TABLE __temp__udb_records AS SELECT id, block, path, identity_path, identity_hash, value,'
                . ' updated_at FROM udb_records',
                'DROP TABLE udb_records',
                'CREATE TABLE udb_records (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, block VARCHAR(1) NOT NULL, path'
                . ' VARCHAR(8192) NOT NULL, identity_path BLOB NOT NULL, identity_hash BLOB NOT NULL, value BLOB NOT NULL,'
                . ' updated_at DATETIME NOT NULL, value_kind SMALLINT DEFAULT 1 NOT NULL)',
                'INSERT INTO udb_records (id, block, path, identity_path, identity_hash, value, updated_at) SELECT id, block,'
                . ' path, identity_path, identity_hash, value, updated_at FROM __temp__udb_records',
                'DROP TABLE __temp__udb_records',
                'CREATE INDEX idx_udb_record_block ON udb_records (block)',
            ],
            $this->platform instanceof PostgreSQLPlatform => [
                'ALTER TABLE udb_authority_state ADD origin_sid VARCHAR(3) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD origin_epoch VARCHAR(16) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD origin_watermark VARCHAR(20) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD dataset_sid VARCHAR(3) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD dataset_epoch VARCHAR(16) DEFAULT NULL',
                'ALTER TABLE udb_authority_state ADD dataset_watermark VARCHAR(20) DEFAULT NULL',
                'ALTER TABLE udb_records ADD value_kind SMALLINT DEFAULT 1 NOT NULL',
            ],
            default => [
                'ALTER TABLE udb_authority_state ADD origin_sid VARCHAR(3) DEFAULT NULL, ADD origin_epoch VARCHAR(16) DEFAULT'
                . ' NULL, ADD origin_watermark VARCHAR(20) DEFAULT NULL, ADD dataset_sid VARCHAR(3) DEFAULT NULL, ADD'
                . ' dataset_epoch VARCHAR(16) DEFAULT NULL, ADD dataset_watermark VARCHAR(20) DEFAULT NULL',
                'ALTER TABLE udb_records ADD value_kind SMALLINT DEFAULT 1 NOT NULL, CHANGE value value VARBINARY(4096) NOT'
                . ' NULL',
            ],
        };
        foreach ($statements as $sql) {
            $this->addSql($sql);
        }

        // Queued fresh-install SQL has no physical UDB rows yet.
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
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql("ALTER TABLE udb_records ALTER COLUMN value TYPE TEXT USING convert_from(value, 'UTF8')");
        }
        foreach (['udb_staged_generations', 'udb_staged_mutations', 'udb_staged_records', 'udb_staged_transfers'] as $table) {
            $this->addSql($this->platform->getDropTableSQL($table));
        }
        $statements = match (true) {
            $this->platform instanceof SQLitePlatform => [
                'CREATE TEMPORARY TABLE __temp__udb_authority_state AS SELECT id, approved, approved_at, fingerprint,'
                . ' protocol_revision FROM udb_authority_state',
                'DROP TABLE udb_authority_state',
                'CREATE TABLE udb_authority_state (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, approved BOOLEAN NOT NULL,'
                . ' approved_at DATETIME DEFAULT NULL, fingerprint VARCHAR(64) DEFAULT NULL, protocol_revision VARCHAR(40)'
                . ' DEFAULT NULL)',
                'INSERT INTO udb_authority_state (id, approved, approved_at, fingerprint, protocol_revision) SELECT id,'
                . ' approved, approved_at, fingerprint, protocol_revision FROM __temp__udb_authority_state',
                'DROP TABLE __temp__udb_authority_state',
                'CREATE TEMPORARY TABLE __temp__udb_records AS SELECT id, block, path, identity_path, identity_hash, value,'
                . ' updated_at FROM udb_records',
                'DROP TABLE udb_records',
                'CREATE TABLE udb_records (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, block VARCHAR(1) NOT NULL, path'
                . ' VARCHAR(8192) NOT NULL, identity_path BLOB NOT NULL, identity_hash BLOB NOT NULL, value CLOB NOT NULL,'
                . ' updated_at DATETIME NOT NULL)',
                'INSERT INTO udb_records (id, block, path, identity_path, identity_hash, value, updated_at) SELECT id, block,'
                . ' path, identity_path, identity_hash, value, updated_at FROM __temp__udb_records',
                'DROP TABLE __temp__udb_records',
                'CREATE INDEX idx_udb_record_block ON udb_records (block)',
                'UPDATE udb_records SET value = CAST(value AS TEXT)',
            ],
            $this->platform instanceof PostgreSQLPlatform => [
                'ALTER TABLE udb_authority_state DROP origin_sid',
                'ALTER TABLE udb_authority_state DROP origin_epoch',
                'ALTER TABLE udb_authority_state DROP origin_watermark',
                'ALTER TABLE udb_authority_state DROP dataset_sid',
                'ALTER TABLE udb_authority_state DROP dataset_epoch',
                'ALTER TABLE udb_authority_state DROP dataset_watermark',
                'ALTER TABLE udb_records DROP value_kind',
            ],
            default => [
                'ALTER TABLE udb_authority_state DROP origin_sid, DROP origin_epoch, DROP origin_watermark, DROP dataset_sid,'
                . ' DROP dataset_epoch, DROP dataset_watermark',
                'ALTER TABLE udb_records DROP value_kind, CHANGE value value LONGTEXT NOT NULL',
            ],
        };
        foreach ($statements as $sql) {
            $this->addSql($sql);
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
