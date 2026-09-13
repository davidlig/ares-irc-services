<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

use function count;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function preg_match;
use function str_starts_with;
use function strcmp;
use function strlen;
use function strtolower;
use function strtoupper;

final class Version20260913160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store current UDB SHA-256 block manifests and their approved protocol revision';
    }

    public function up(Schema $schema): void
    {
        $records = $schema->getTable('udb_records');
        // A textual collation is not sufficient here: MySQL/MariaDB normally
        // inherit a case-insensitive utf8mb4 collation, while only the K::F
        // pattern component has byte-exact identity. VARBINARY gives the
        // identity column identical comparison semantics on every platform and
        // keeps both the unique constraint and equality lookups case-sensitive.
        $records->modifyColumn('identity_path', ['type' => Type::getType(Types::BINARY)]);
        if ($this->platform instanceof PostgreSQLPlatform) {
            // PostgreSQL has no implicit text -> bytea cast. This explicit
            // conversion runs before Doctrine's schema diff ALTER and
            // preserves the UTF-8 bytes used by protocol identity comparison.
            $this->addSql("ALTER TABLE udb_records ALTER identity_path TYPE BYTEA USING convert_to(identity_path, 'UTF8')");
        }

        $states = $schema->getTable('udb_block_states');
        $states->modifyColumn('checksum', ['length' => 64]);
        $states->addColumn('record_count', 'integer', ['default' => 0]);
        $states->renameColumn('synced_at', 'modified_at');

        $authority = $schema->getTable('udb_authority_state');
        $authority->addColumn('protocol_revision', 'string', ['length' => 40, 'notnull' => false]);
    }

    public function postUp(Schema $schema): void
    {
        /** @var list<array{id: int, block: string, path: string, value: string}> $records */
        $records = $this->connection->fetchAllAssociative('SELECT id, block, path, value FROM udb_records');

        foreach ($records as $record) {
            // N/C/K are projections owned by SQL. The initializer merges its
            // current projection into the record store, so retaining any row
            // here would allow data that disappeared from SQL to survive the
            // forced reseed and become authoritative again.
            if (in_array(strtoupper($record['block']), ['N', 'C', 'K'], true)) {
                $this->connection->delete('udb_records', ['id' => $record['id']]);

                continue;
            }

            $this->connection->update(
                'udb_records',
                [
                    'identity_path' => self::currentIdentity($record['block'], $record['path']),
                    'value' => self::canonicalNumericValue($record['value']),
                ],
                ['id' => $record['id']],
                ['identity_path' => Types::BINARY],
            );
        }

        // Legacy CRC32 rows cannot be converted into manifests. Removing the
        // markers makes initialization reseed the now-empty N/C/K blocks from
        // SQL and rebuild all six manifests before authority can be approved.
        $this->connection->delete('udb_block_states');
        $this->connection->update('udb_authority_state', [
            'approved' => 0,
            'approved_at' => null,
            'fingerprint' => null,
            'protocol_revision' => null,
        ]);
    }

    public function preDown(Schema $schema): void
    {
        $identityProjection = $this->platform instanceof PostgreSQLPlatform
            ? "convert_from(identity_path, 'UTF8') AS identity_path"
            : 'identity_path';
        /** @var list<array{id: int, block: string, identity_path: string}> $records */
        $records = $this->connection->fetchAllAssociative('SELECT id, block, ' . $identityProjection . ' FROM udb_records');
        $legacyIdentities = [];

        foreach ($records as $record) {
            $legacyIdentity = strtolower($record['identity_path']);
            $key = strtoupper($record['block']) . "\0" . $legacyIdentity;
            if (isset($legacyIdentities[$key])) {
                $this->throwIrreversibleMigrationException(
                    'Cannot restore case-insensitive legacy identities: K::F patterns now differ only by case.',
                );
            }

            $legacyIdentities[$key] = true;
        }

        // Fold in PHP: LOWER(VARBINARY(...)) is platform-dependent and may be a
        // no-op on MySQL/MariaDB, which would miss collisions during downgrade.
        foreach ($records as $record) {
            $this->connection->update(
                'udb_records',
                ['identity_path' => strtolower($record['identity_path'])],
                ['id' => $record['id']],
                ['identity_path' => Types::BINARY],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('udb_records')->modifyColumn('identity_path', ['type' => Type::getType(Types::STRING)]);
        if ($this->platform instanceof PostgreSQLPlatform) {
            // Reverse convert_to() explicitly; PostgreSQL likewise has no
            // implicit bytea -> text cast for this ALTER TYPE.
            $this->addSql("ALTER TABLE udb_records ALTER identity_path TYPE VARCHAR(8192) USING convert_from(identity_path, 'UTF8')");
        }

        $states = $schema->getTable('udb_block_states');
        $states->modifyColumn('checksum', ['length' => 8]);
        $states->dropColumn('record_count');
        $states->renameColumn('modified_at', 'synced_at');

        $schema->getTable('udb_authority_state')->dropColumn('protocol_revision');
    }

    private static function currentIdentity(string $block, string $path): string
    {
        if ('K' !== strtoupper($block)) {
            return strtolower($path);
        }

        $components = explode('::', $path);
        if (count($components) < 2 || 'F' !== strtoupper($components[0])) {
            return strtolower($path);
        }

        $components[0] = 'f';
        for ($index = 2; $index < count($components); ++$index) {
            $components[$index] = strtolower($components[$index]);
        }

        return implode('::', $components);
    }

    private static function canonicalNumericValue(string $value): string
    {
        if (!str_starts_with($value, '*') || 1 !== preg_match('/^[0-9]+\z/', substr($value, 1))) {
            return $value;
        }

        $digits = ltrim(substr($value, 1), '0');
        $digits = '' === $digits ? '0' : $digits;
        $maximum = '18446744073709551615';
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
        ) {
            return $value;
        }

        return '*' . $digits;
    }
}
