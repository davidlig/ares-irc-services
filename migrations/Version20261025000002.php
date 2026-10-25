<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025000002 extends AbstractMigration
{
    private const array INDEXES = [
        ['udb_records', 'uniq_udb_records_block_identity', 'block, identity_hash'],
        ['udb_block_states', 'uniq_udb_block_states_block', 'block'],
    ];

    public function getDescription(): string
    {
        return 'Restore introspectable UDB uniqueness without rebuilding tables';
    }

    public function isTransactional(): bool
    {
        return !$this->platform instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as [$table, $name, $columns]) {
            $this->addSql('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table . ' (' . $columns . ')');
        }
        // UNIQ_pattern already covers this identical ordinary index.
        $this->addSql($this->platform->getDropIndexSQL('idx_pattern', 'forbidden_vhosts'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_pattern ON forbidden_vhosts (pattern)');
        foreach (self::INDEXES as [$table, $name]) {
            $this->addSql($this->platform->getDropIndexSQL($name, $table));
        }
    }
}
