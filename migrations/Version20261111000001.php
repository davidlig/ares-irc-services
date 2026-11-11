<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

use function is_string;

final class Version20261111000001 extends AbstractMigration
{
    private const array INDEXES = [
        ['registered_channels', 'idx_channels_founder_status', 'founder_nick_id, status'],
        ['registered_nicks', 'idx_nicks_email_search', 'email_search_key'],
        ['registered_nicks', 'idx_nicks_status_suspended', 'status, suspended_until'],
        ['registered_nicks', 'idx_nicks_status_inactive', 'status, no_expire, last_seen_at'],
        ['channel_akick', 'idx_channel_akicks_expires', 'expires_at'],
        ['channel_history', 'idx_channel_history_channel_performed', 'channel_id, performed_at'],
        ['memo_ignores', 'idx_memo_ignores_ignored_nick', 'ignored_nick_id'],
    ];

    public function getDescription(): string
    {
        return 'Index maintenance and account lookup queries';
    }

    public function isTransactional(): bool
    {
        return !$this->platform instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $kind = $this->platform instanceof PostgreSQLPlatform ? 'STORED' : 'VIRTUAL';
        $collation = '';
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $metadata = $this->connection->fetchAssociative("SELECT CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registered_nicks' AND COLUMN_NAME = 'email'");
            if (false === $metadata && !$this->connection->createSchemaManager()->tablesExist(['registered_nicks'])) {
                $metadata = $this->connection->fetchAssociative('SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
            }
            $charset = $metadata['charset'] ?? null;
            $name = $metadata['collation'] ?? null;
            if (!is_string($charset) || '' === $charset || !is_string($name) || '' === $name) {
                throw new AbortMigration('Cannot determine the source collation for registered_nicks.email');
            }
            $collation = ' CHARACTER SET ' . $this->platform->quoteSingleIdentifier($charset) . ' COLLATE ' . $this->platform->quoteSingleIdentifier($name);
        }
        $this->addSql('ALTER TABLE registered_nicks ADD COLUMN email_search_key VARCHAR(255)' . $collation . ' GENERATED ALWAYS AS (LOWER(email)) ' . $kind);
        $dateType = $this->platform instanceof PostgreSQLPlatform ? 'TIMESTAMP' : 'DATETIME';
        $this->addSql('CREATE TABLE migration_172_last_seen_backup (nick_id INTEGER NOT NULL PRIMARY KEY, backfilled_at ' . $dateType . ' NOT NULL)');
        $this->addSql('INSERT INTO migration_172_last_seen_backup (nick_id, backfilled_at) SELECT id, registered_at FROM registered_nicks WHERE last_seen_at IS NULL AND registered_at IS NOT NULL');
        $this->addSql('UPDATE registered_nicks SET last_seen_at = registered_at WHERE last_seen_at IS NULL AND registered_at IS NOT NULL');
        foreach (self::INDEXES as [$table, $name, $columns]) {
            $this->addSql('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $columns . ')');
        }
        $this->addSql($this->platform->getDropIndexSQL('idx_name_lower', 'registered_channels'));
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('CREATE INDEX idx_name_lower ON registered_channels (name_lower)');
        foreach (self::INDEXES as [$table, $name]) {
            $this->addSql($this->platform->getDropIndexSQL($name, $table));
        }
        $this->addSql('UPDATE registered_nicks SET last_seen_at = NULL WHERE EXISTS (SELECT 1 FROM migration_172_last_seen_backup b WHERE b.nick_id = registered_nicks.id AND b.backfilled_at = registered_nicks.last_seen_at)');
        $this->addSql('DROP TABLE migration_172_last_seen_backup');
        $this->addSql('ALTER TABLE registered_nicks DROP COLUMN email_search_key');
    }

    private function assertSupportedPlatform(): void
    {
        $this->abortIf(
            !$this->platform instanceof SQLitePlatform
            && !$this->platform instanceof PostgreSQLPlatform
            && !$this->platform instanceof AbstractMySQLPlatform,
            'These indexes require SQLite, PostgreSQL, MySQL or MariaDB.',
        );
    }
}
