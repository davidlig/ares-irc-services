<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Migrations\Version20261114000001;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function count;

/** Migrations are outside the production coverage filter; exercise them on a physical schema. */
#[CoversNothing]
final class IdentityFoldMigrationTest extends TestCase
{
    private Connection $connection;

    #[Test]
    public function upRewritesOnlyKeysThatChangeUnderTheRfc1459Fold(): void
    {
        $this->insertNick(1, '[Foo]\\^', '[foo]\\^');
        $this->insertNick(2, 'Plain', 'plain');
        $this->insertNick(3, 'École', 'École');
        $this->insertChannel(10, '#[Ares]', '#[ares]');
        $this->insertChannel(11, '#ares', '#ares');
        $this->insertReservation('unreal', '[bot]', '[Bot]');
        $this->insertReservation('unreal', 'other', 'Other');

        $statements = $this->migrate('up');

        self::assertSame(3, $statements);
        self::assertSame(
            [[1, '{foo}|~'], [2, 'plain'], [3, 'École']],
            $this->keys('SELECT id, nickname_lower FROM registered_nicks ORDER BY id'),
        );
        self::assertSame([[10, '#{ares}'], [11, '#ares']], $this->keys('SELECT id, name_lower FROM registered_channels ORDER BY id'));
        self::assertSame([['other', 'Other'], ['{bot}', '[Bot]']], $this->keys('SELECT nickname_lower, nickname FROM service_nick_reservations ORDER BY nickname_lower'));
        self::assertSame(0, $this->migrate('up'));
    }

    #[Test]
    public function equivalentReservationSpellingsCollapseIntoTheRowHoldingTheFoldedKey(): void
    {
        $this->insertReservation('unreal', '[bot]', '[Bot]');
        $this->insertReservation('unreal', '{bot}', '{Bot}');
        $this->insertReservation('inspircd', '[bot]', '[Bot]');

        $this->migrate('up');

        self::assertSame(
            [['inspircd', '{bot}', '[Bot]'], ['unreal', '{bot}', '{Bot}']],
            $this->keys('SELECT protocol, nickname_lower, nickname FROM service_nick_reservations ORDER BY protocol'),
        );
    }

    #[Test]
    public function collidingIdentitiesAbortBeforeAnyChangeAndListTheNames(): void
    {
        $this->insertNick(1, '[Foo]', '[foo]');
        $this->insertNick(2, '{foo}', '{foo}');
        $this->insertNick(3, 'Bar\\', 'bar\\');
        $this->insertNick(4, 'BAR|', 'bar|');
        $this->insertNick(5, 'Plain', 'Plain');
        $before = $this->keys('SELECT id, nickname, nickname_lower FROM registered_nicks ORDER BY id');
        $migration = new Version20261114000001($this->connection, new NullLogger());

        try {
            $migration->preUp($this->connection->createSchemaManager()->introspectSchema());
            self::fail('Colliding identities must abort the migration.');
        } catch (AbortMigration $exception) {
            self::assertSame(
                'Cannot fold registered_nicks.nickname_lower: these names become the same identity and one of each group must be dropped or renamed first: [Foo], {foo}; BAR|, Bar\\',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $migration->getSql());
        self::assertSame($before, $this->keys('SELECT id, nickname, nickname_lower FROM registered_nicks ORDER BY id'));
    }

    #[Test]
    public function channelCollisionsAbortAsWell(): void
    {
        $this->insertChannel(10, '#[x]', '#[x]');
        $this->insertChannel(11, '#{X}', '#{x}');

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('Cannot fold registered_channels.name_lower: these names become the same identity and one of each group must be dropped or renamed first: #[x], #{X}');

        new Version20261114000001($this->connection, new NullLogger())->preUp($this->connection->createSchemaManager()->introspectSchema());
    }

    #[Test]
    public function updatesAreOrderedAroundStaleKeysAndCyclesAbort(): void
    {
        $this->insertNick(1, '[x]', '[x]');
        $this->insertNick(2, 'Other', '{x}');

        $this->migrate('up');

        self::assertSame([[1, '{x}'], [2, 'other']], $this->keys('SELECT id, nickname_lower FROM registered_nicks ORDER BY id'));

        $this->connection->executeStatement("UPDATE registered_nicks SET nickname_lower = 'tmp' WHERE id = 1");
        $this->connection->executeStatement("UPDATE registered_nicks SET nickname_lower = '{x}' WHERE id = 2");
        $this->connection->executeStatement("UPDATE registered_nicks SET nickname_lower = 'other' WHERE id = 1");

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('Cannot rewrite registered_nicks.nickname_lower: stored keys form a cycle; repair them before migrating.');

        new Version20261114000001($this->connection, new NullLogger())->preUp($this->connection->createSchemaManager()->introspectSchema());
    }

    #[Test]
    public function downRestoresAsciiKeysWithParameterFreeStatements(): void
    {
        $this->insertNick(1, '[Foo]\\^', '{foo}|~');
        $this->insertNick(2, "O'Brien", "o'brien");
        $this->insertChannel(10, '#[Ares]', '#{ares}');
        $this->insertReservation('unreal', '{bot}', '[Bot]');
        $migration = new Version20261114000001($this->connection, new NullLogger());
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        $migration->preDown($schema);
        $migration->down($schema);

        self::assertCount(3, $migration->getSql());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $this->connection->executeStatement($query->getStatement());
        }

        self::assertSame([[1, '[foo]\\^'], [2, "o'brien"]], $this->keys('SELECT id, nickname_lower FROM registered_nicks ORDER BY id'));
        self::assertSame([[10, '#[ares]']], $this->keys('SELECT id, name_lower FROM registered_channels'));
        self::assertSame([['[bot]', '[Bot]']], $this->keys('SELECT nickname_lower, nickname FROM service_nick_reservations'));
    }

    #[Test]
    public function freshDatabasesWithoutTablesPlanNothing(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261114000001($connection, new NullLogger());
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration->preUp($schema);
        $migration->up($schema);
        $migration->preDown($schema);
        $migration->down($schema);

        self::assertSame([], $migration->getSql());
        $connection->close();
    }

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY, nickname VARCHAR(32) NOT NULL, nickname_lower VARCHAR(32) NOT NULL)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_nickname_lower ON registered_nicks (nickname_lower)');
        $this->connection->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL, name_lower VARCHAR(64) NOT NULL)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_name_lower ON registered_channels (name_lower)');
        $this->connection->executeStatement('CREATE TABLE service_nick_reservations (protocol VARCHAR(32) NOT NULL, nickname_lower VARCHAR(64) NOT NULL, nickname VARCHAR(64) NOT NULL, PRIMARY KEY(protocol, nickname_lower))');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /** Runs one direction like the executor: preflight, queue, then execute the queued statements. */
    private function migrate(string $direction): int
    {
        $migration = new Version20261114000001($this->connection, new NullLogger());
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        if ('up' === $direction) {
            $migration->preUp($schema);
            $migration->up($schema);
        } else {
            $migration->preDown($schema);
            $migration->down($schema);
        }
        foreach ($migration->getSql() as $query) {
            // The migration binds positional parameters only.
            $parameters = [];
            foreach ($query->getParameters() as $value) {
                $parameters[] = $value;
            }
            $types = [];
            foreach ($query->getTypes() as $type) {
                self::assertInstanceOf(ParameterType::class, $type);
                $types[] = $type;
            }
            $this->connection->executeStatement($query->getStatement(), $parameters, $types);
        }

        return count($migration->getSql());
    }

    private function insertNick(int $id, string $nickname, string $key): void
    {
        $this->connection->insert('registered_nicks', ['id' => $id, 'nickname' => $nickname, 'nickname_lower' => $key]);
    }

    private function insertChannel(int $id, string $name, string $key): void
    {
        $this->connection->insert('registered_channels', ['id' => $id, 'name' => $name, 'name_lower' => $key]);
    }

    private function insertReservation(string $protocol, string $key, string $nickname): void
    {
        $this->connection->insert('service_nick_reservations', ['protocol' => $protocol, 'nickname_lower' => $key, 'nickname' => $nickname]);
    }

    /** @return list<list<mixed>> */
    private function keys(string $sql): array
    {
        return $this->connection->fetchAllNumeric($sql);
    }
}
