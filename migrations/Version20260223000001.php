<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enforce unique email per nickname: one email cannot be used for more than one account.
 * Existing duplicate emails are made unique (suffix duplicate-{id}@invalid.ares) so the index can be created.
 * SQLite allows multiple NULLs in a UNIQUE column; only non-null emails are unique.
 */
final class Version20260223000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on registered_nicks.email (one email per account)';
    }

    public function up(Schema $schema): void
    {
        // Make duplicate emails unique: keep the row with the smallest id per (lowercase) email, suffix the rest
        $this->addSql(<<<'SQL'
            UPDATE registered_nicks
            SET email = 'duplicate-' || id || '@invalid.ares'
            WHERE id IN (
                SELECT r2.id FROM registered_nicks r2
                INNER JOIN (
                    SELECT LOWER(email) AS le, MIN(id) AS mid
                    FROM registered_nicks
                    WHERE email IS NOT NULL
                    GROUP BY LOWER(email)
                ) AS first ON LOWER(r2.email) = first.le AND r2.id <> first.mid
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX idx_registered_nicks_email ON registered_nicks (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_registered_nicks_email');
    }
}
