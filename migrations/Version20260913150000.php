<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

use function implode;
use function is_string;
use function sprintf;
use function strtoupper;

final class Version20260913150000 extends AbstractMigration
{
    private const string LEGACY_TABLE = 'channel_history__legacy_json';

    public function getDescription(): string
    {
        return 'Normalize the legacy SQLite JSON declaration of channel_history.extra_data';
    }

    public function up(Schema $schema): void
    {
        // DBAL declares JSON columns as CLOB on SQLite. Databases created by
        // the pre-Schema-API migration carry a raw "JSON" declaration that
        // SQLiteSchemaManager cannot map back to a Doctrine type, breaking
        // every migration that introspects the schema.
        if (!$this->platform instanceof SQLitePlatform || !$this->declaresLegacyJsonColumn()) {
            return;
        }

        $table = $this->canonicalTable();
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[] = $column->getQuotedName($this->platform);
        }

        $columnList = implode(', ', $columns);

        // SQLite index names are database-global, so the legacy indexes must
        // be dropped before the canonical table can reuse their names.
        foreach ($table->getIndexes() as $index) {
            $this->addSql('DROP INDEX IF EXISTS ' . $index->getQuotedName($this->platform));
        }

        // SQLite cannot alter a column declaration in place: rebuild the table
        // under a temporary name and swap it once the rows are copied.
        $this->addSql('ALTER TABLE channel_history RENAME TO ' . self::LEGACY_TABLE);
        foreach ($this->platform->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }

        $this->addSql(sprintf(
            'INSERT INTO channel_history (%s) SELECT %s FROM %s',
            $columnList,
            $columnList,
            self::LEGACY_TABLE,
        ));
        $this->addSql('DROP TABLE ' . self::LEGACY_TABLE);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Restoring the legacy JSON declaration would reintroduce a SQLite schema Doctrine DBAL cannot introspect.',
        );
    }

    private function declaresLegacyJsonColumn(): bool
    {
        $declaration = $this->connection->fetchOne(
            "SELECT type FROM pragma_table_info('channel_history') WHERE lower(name) = 'extra_data'",
        );

        return is_string($declaration) && 'JSON' === strtoupper($declaration);
    }

    private function canonicalTable(): Table
    {
        $schema = new Schema();
        $table = $schema->createTable('channel_history');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('channel_id', Types::INTEGER);
        $table->addColumn('action', Types::STRING, ['length' => 50]);
        $table->addColumn('performed_by', Types::STRING, ['length' => 32]);
        $table->addColumn('performed_by_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('performed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('message', Types::STRING, ['length' => 512]);
        $table->addColumn('extra_data', Types::TEXT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['channel_id'], 'idx_ch_history_channel_id');
        $table->addIndex(['performed_at'], 'idx_ch_history_performed_at');

        return $table;
    }
}
