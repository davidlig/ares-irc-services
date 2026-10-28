<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

use function in_array;

final class Version20261027000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow memos to snapshot a service nickname as their sender';
    }

    public function preDown(Schema $schema): void
    {
        // Fresh dry runs have no physical memo table or records to inspect.
        if ($this->connection->createSchemaManager()->tablesExist(['memos'])
            && !in_array($this->connection->fetchOne('SELECT COUNT(*) FROM memos WHERE sender_name IS NOT NULL'), [false, 0, '0'], true)) {
            throw new RuntimeException('Cannot remove service memo sender support while service-authored memos exist.');
        }
    }

    public function up(Schema $schema): void
    {
        $statements = match (true) {
            $this->platform instanceof SQLitePlatform => [
                'CREATE TEMPORARY TABLE __temp__memos AS SELECT id, target_nick_id, target_channel_id, sender_nick_id,'
                . ' message, created_at, read_at FROM memos',
                'DROP TABLE memos',
                'CREATE TABLE memos (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL,'
                . ' target_channel_id INTEGER DEFAULT NULL, sender_nick_id INTEGER DEFAULT NULL, message CLOB NOT NULL,'
                . ' created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, sender_name VARCHAR(64) DEFAULT NULL)',
                'INSERT INTO memos (id, target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at)'
                . ' SELECT id, target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at FROM __temp__memos',
                'DROP TABLE __temp__memos',
                'CREATE INDEX idx_memos_sender ON memos (sender_nick_id)',
                'CREATE INDEX idx_memos_target_channel ON memos (target_channel_id)',
                'CREATE INDEX idx_memos_target_nick ON memos (target_nick_id)',
            ],
            $this->platform instanceof PostgreSQLPlatform => [
                'ALTER TABLE memos ADD sender_name VARCHAR(64) DEFAULT NULL',
                'ALTER TABLE memos ALTER sender_nick_id DROP NOT NULL',
            ],
            default => [
                'ALTER TABLE memos ADD sender_name VARCHAR(64) DEFAULT NULL, CHANGE sender_nick_id sender_nick_id INT DEFAULT'
                . ' NULL',
            ],
        };
        foreach ($statements as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $statements = match (true) {
            $this->platform instanceof SQLitePlatform => [
                'CREATE TEMPORARY TABLE __temp__memos AS SELECT id, target_nick_id, target_channel_id, sender_nick_id,'
                . ' message, created_at, read_at FROM memos',
                'DROP TABLE memos',
                'CREATE TABLE memos (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, target_nick_id INTEGER DEFAULT NULL,'
                . ' target_channel_id INTEGER DEFAULT NULL, sender_nick_id INTEGER NOT NULL, message CLOB NOT NULL, created_at'
                . ' DATETIME NOT NULL, read_at DATETIME DEFAULT NULL)',
                'INSERT INTO memos (id, target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at)'
                . ' SELECT id, target_nick_id, target_channel_id, sender_nick_id, message, created_at, read_at FROM __temp__memos',
                'DROP TABLE __temp__memos',
                'CREATE INDEX idx_memos_target_nick ON memos (target_nick_id)',
                'CREATE INDEX idx_memos_target_channel ON memos (target_channel_id)',
                'CREATE INDEX idx_memos_sender ON memos (sender_nick_id)',
            ],
            $this->platform instanceof PostgreSQLPlatform => [
                'ALTER TABLE memos DROP sender_name',
                'ALTER TABLE memos ALTER sender_nick_id SET NOT NULL',
            ],
            default => [
                'ALTER TABLE memos DROP sender_name, CHANGE sender_nick_id sender_nick_id INT NOT NULL',
            ],
        };
        foreach ($statements as $sql) {
            $this->addSql($sql);
        }
    }
}
