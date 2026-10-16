<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20261013000002 extends AbstractMigration implements EditedSchemaMigration
{
    /** Name Doctrine generated from the former unique="true" on the email field. */
    private const string EMAIL_UNIQUE_INDEX = 'UNIQ_2E946F37E7927C74';

    private const string TABLE = 'registered_nicks';

    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Allow several nicknames to share one e-mail address by dropping the unique index';
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
        $editor = (clone $schema)->edit();

        // Absent on a fresh install: the mapping no longer declares a unique e-mail.
        if ($this->hasEmailUniqueIndex($schema)) {
            $editor->modifyTableByUnquotedName(self::TABLE, static function (TableEditor $table): void {
                $table->dropIndexByUnquotedName(self::EMAIL_UNIQUE_INDEX);
            });

            // Through the comparator SQLite would rebuild the whole table, which
            // also reshuffles the query planner's index choice. A plain DROP INDEX
            // reaches the same schema without touching anything else. MySQL and
            // MariaDB reject the bare form, so they qualify it with the table.
            $dropIndex = 'DROP INDEX ' . self::EMAIL_UNIQUE_INDEX;
            if ($this->platform instanceof AbstractMySQLPlatform) {
                $dropIndex .= ' ON ' . self::TABLE;
            }

            $this->addSql($dropIndex);
        }

        $this->editedSchema = clone $editor->create();
    }

    public function down(Schema $schema): void
    {
        $this->assertNoSharedAddresses();

        $editor = (clone $schema)->edit();
        $editor->modifyTableByUnquotedName(self::TABLE, static function (TableEditor $table): void {
            $table->addIndex(Index::editor()
                ->setUnquotedName(self::EMAIL_UNIQUE_INDEX)
                ->setType(IndexType::UNIQUE)
                ->setUnquotedColumnNames(...['email'])
                ->create());
        });

        $this->editedSchema = clone $editor->create();
        $this->addSql(
            'CREATE UNIQUE INDEX ' . self::EMAIL_UNIQUE_INDEX . ' ON ' . self::TABLE . ' (email)'
        );
    }

    private function hasEmailUniqueIndex(Schema $schema): bool
    {
        return $schema->hasTable(self::TABLE)
            && $schema->getTable(self::TABLE)->hasIndex(self::EMAIL_UNIQUE_INDEX);
    }

    /** Restoring the constraint is impossible while several nicknames share an address. */
    private function assertNoSharedAddresses(): void
    {
        if (!$this->connection->createSchemaManager()->tablesExist([self::TABLE])) {
            return;
        }

        $shared = $this->connection->executeQuery(
            'SELECT COUNT(*) FROM (SELECT LOWER(email) FROM ' . self::TABLE
            . ' WHERE email IS NOT NULL GROUP BY LOWER(email) HAVING COUNT(*) > 1) shared'
        )->fetchOne();

        if (false !== $shared && 0 < (int) $shared) {
            throw new RuntimeException('Cannot restore the unique e-mail constraint: some addresses are shared by several nicknames.');
        }
    }
}
