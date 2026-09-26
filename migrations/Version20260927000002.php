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
use function sprintf;

final class Version20260927000002 extends AbstractMigration
{
    private const array INDEXES = [
        ['registered_nicks', 'nickname_lower', 32, 'idx_registered_nicks_list_pattern'],
        ['registered_channels', 'name_lower', 64, 'idx_registered_channels_list_pattern'],
    ];

    public function getDescription(): string
    {
        return 'Index LIST patterns while preserving database LOWER and collation semantics';
    }

    public function isTransactional(): bool
    {
        return !$this->platform instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        foreach (self::INDEXES as [$table, $column, $length, $index]) {
            if ($this->platform instanceof AbstractMySQLPlatform) {
                $metadata = $this->connection->fetchAssociative(
                    'SELECT CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                    ['table' => $table, 'column' => $column],
                );
                $charset = $metadata['charset'] ?? null;
                $collation = $metadata['collation'] ?? null;
                if (!is_string($charset) || '' === $charset || !is_string($collation) || '' === $collation) {
                    throw new AbortMigration('Cannot determine the source collation for ' . $table . '.' . $column);
                }
                $this->addSql(sprintf(
                    'ALTER TABLE %s ADD list_search_key VARCHAR(%d) CHARACTER SET %s COLLATE %s GENERATED ALWAYS AS (LOWER(%s)) VIRTUAL',
                    $table,
                    $length,
                    $this->platform->quoteSingleIdentifier($charset),
                    $this->platform->quoteSingleIdentifier($collation),
                    $column,
                ));
                $expression = 'list_search_key';
            } else {
                $expression = $this->platform instanceof SQLitePlatform
                    ? $column . ' COLLATE NOCASE'
                    : 'LOWER(' . $column . ') text_pattern_ops';
            }
            // Native expression indexes and generated columns are migration-owned, not ORM metadata.
            $this->addSql('CREATE INDEX ' . $index . ' ON ' . $table . ' (' . $expression . ')');
        }
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        foreach (self::INDEXES as [$table, , , $index]) {
            $this->addSql($this->platform->getDropIndexSQL($index, $table));
        }
        if ($this->platform instanceof AbstractMySQLPlatform) {
            foreach (self::INDEXES as [$table]) {
                $this->addSql('ALTER TABLE ' . $table . ' DROP COLUMN list_search_key');
            }
        }
    }

    private function assertSupportedPlatform(): void
    {
        $this->abortIf(
            !$this->platform instanceof SQLitePlatform
            && !$this->platform instanceof PostgreSQLPlatform
            && !$this->platform instanceof AbstractMySQLPlatform,
            'LIST indexes require SQLite, PostgreSQL, MySQL or MariaDB.',
        );
    }
}
