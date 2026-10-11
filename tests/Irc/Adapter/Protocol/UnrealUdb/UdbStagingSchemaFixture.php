<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use Doctrine\DBAL\Connection;

/** Creates the migration-owned staging tables for disposable SQLite tests. */
final class UdbStagingSchemaFixture
{
    public static function create(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE udb_staged_generations (generation_id VARCHAR(32) PRIMARY KEY, round_id VARCHAR(20) NOT NULL, root_sid VARCHAR(3) NOT NULL, root_epoch VARCHAR(16) NOT NULL, watermark VARCHAR(20) NOT NULL, created_at INTEGER NOT NULL, promoted BOOLEAN NOT NULL DEFAULT 0)');
        $connection->executeStatement('CREATE TABLE udb_staged_transfers (generation_id VARCHAR(32) NOT NULL, block VARCHAR(1) NOT NULL, round_id VARCHAR(20) NOT NULL, txid VARCHAR(31) NOT NULL, digest VARCHAR(64) NOT NULL, watermark VARCHAR(20) NOT NULL, expected_count INTEGER NOT NULL, record_count INTEGER NOT NULL, byte_count INTEGER NOT NULL, complete BOOLEAN NOT NULL DEFAULT 0, PRIMARY KEY (generation_id, block))');
        $connection->executeStatement('CREATE TABLE udb_staged_records (id INTEGER PRIMARY KEY AUTOINCREMENT, generation_id VARCHAR(32) NOT NULL, block VARCHAR(1) NOT NULL, path VARCHAR(8192) NOT NULL, identity_path BLOB NOT NULL, identity_hash BLOB NOT NULL, value BLOB NOT NULL, value_kind INTEGER NOT NULL, UNIQUE (generation_id, block, identity_hash))');
        $connection->executeStatement('CREATE TABLE udb_staged_mutations (id INTEGER PRIMARY KEY AUTOINCREMENT, generation_id VARCHAR(32) NOT NULL, sequence VARCHAR(20) NOT NULL, block VARCHAR(1) NOT NULL, operation VARCHAR(3) NOT NULL, path VARCHAR(8192) NOT NULL, value BLOB DEFAULT NULL, value_kind INTEGER DEFAULT NULL, UNIQUE (generation_id, sequence))');
    }
}
