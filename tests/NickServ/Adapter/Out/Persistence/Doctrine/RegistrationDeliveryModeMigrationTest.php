<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261021000003;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class RegistrationDeliveryModeMigrationTest extends TestCase
{
    public function testUpgradeRetainsExistingAccountsAsEmailAndPreservesSearchIndex(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY, nickname_lower VARCHAR(32) NOT NULL)');
        $connection->executeStatement('CREATE INDEX idx_registered_nicks_list_pattern ON registered_nicks (nickname_lower COLLATE NOCASE)');
        $connection->insert('registered_nicks', ['id' => 1, 'nickname_lower' => 'existing']);
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration = new Version20261021000003($connection, new NullLogger());
        self::assertNotSame('', $migration->getDescription());
        self::assertNull($migration->getEditedSchema());
        $migration->preUp($schema);
        $migration->up($schema);
        self::assertFalse($schema->getTable('registered_nicks')->hasColumn('registration_mode'));
        $target = $migration->getEditedSchema();
        self::assertInstanceOf(Schema::class, $target);
        self::assertSame(5, $target->getTable('registered_nicks')->getColumn('registration_mode')->getLength());
        self::assertNotSame($target, $migration->getEditedSchema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            self::assertSame([], $query->getTypes());
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame([['id' => 1, 'nickname_lower' => 'existing', 'registration_mode' => 'EMAIL']], $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));
        self::assertNotFalse($connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'idx_registered_nicks_list_pattern'"));
        $rollback = new Version20261021000003($connection, new NullLogger());
        $rollback->preDown($target);
        self::assertNull($rollback->getEditedSchema());
        $rollback->down($target);
        foreach ($rollback->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            self::assertSame([], $query->getTypes());
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame([['id' => 1, 'nickname_lower' => 'existing']], $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));
        self::assertNotFalse($connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'idx_registered_nicks_list_pattern'"));
        $connection->close();
    }
}
