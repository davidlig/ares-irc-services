<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Persistence;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function in_array;
use function is_int;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/** Real migrations and query plans, never an ambient application/production connection. */
#[CoversNothing]
#[Group('integration')]
final class DatabaseQueryOptimizationTest extends TestCase
{
    private Connection $connection;

    private EntityManager $entityManager;

    private QueryCaptureLogger $logger;

    private bool $ownsSchema = false;

    private bool $createdSqliteStatistics = false;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../../config/doctrine/nickserv' => 'App\\NickServ\\Domain\\Entity',
            __DIR__ . '/../../../config/doctrine/chanserv' => 'App\\ChanServ\\Domain\\Entity',
        ]));
        $config->setProxyDir(__DIR__ . '/../../../var/cache/test/Proxies');
        $config->setProxyNamespace('App\\Tests\\Proxies');
        $this->logger = new QueryCaptureLogger();
        $config->setMiddlewares([new Middleware($this->logger)]);
        $dsn = getenv('ARES_QUERY_TEST_DATABASE_URL');
        $params = false === $dsn || '' === $dsn
            ? ['driver' => 'pdo_sqlite', 'memory' => true]
            : new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite'])->parse($dsn);
        $this->connection = DriverManager::getConnection($params, $config);
        self::assertSame([], $this->connection->createSchemaManager()->listTableNames(), 'The explicit query-test database must be EMPTY; refusing to modify existing data.');
        $this->ownsSchema = true;
        $this->entityManager = new EntityManager($this->connection, $config);
        $this->migrate('App\\Migrations\\Version20260926000002');
    }

    protected function tearDown(): void
    {
        if ($this->ownsSchema) {
            $this->removeFixtureStatistics();
            $this->entityManager->close();
            $this->migrate('0');
            $this->connection->executeStatement('DROP TABLE doctrine_migration_versions');
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    #[Test]
    public function migratedRepositoriesPreserveResultsAndUseSelectiveIndexes(): void
    {
        $this->seedEdgeCases();
        $before = $this->snapshot();
        $this->migrate('latest');
        self::assertSame($before, $this->snapshot());
        $this->assertIndexesPresent();
        $this->assertLegacyListEquivalence();
        $this->assertWhoipEquivalence();

        $this->seedCardinality();
        $before = $this->snapshot();
        $this->refreshStatistics();
        $this->assertSelectivePlans();
        $this->removeFixtureStatistics();
        self::assertTrue($this->connection->createSchemaManager()->introspectSchema()->hasTable('registered_nicks'), 'Optimized indexes must remain compatible with later Doctrine schema migrations.');

        $this->entityManager->clear();
        $this->migrate('App\\Migrations\\Version20260926000002');
        $this->assertIndexesPresent(false);
        self::assertSame($before, $this->snapshot(), 'Rollback must preserve all original account/channel data.');
        $this->migrate('latest');
        self::assertSame($before, $this->snapshot(), 'Reapplying must preserve all original account/channel data.');
        $this->assertIndexesPresent();
        $this->assertLegacyListEquivalence();
    }

    private function migrate(string $version): void
    {
        $factory = DependencyFactory::fromConnection(
            new ConfigurationArray(['migrations_paths' => ['App\\Migrations' => __DIR__ . '/../../../migrations']]),
            new ExistingConnection($this->connection),
        );
        $factory->getMetadataStorage()->ensureInitialized();
        $target = $factory->getVersionAliasResolver()->resolveVersionAlias($version);
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($target);
        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    private function seedEdgeCases(): void
    {
        // Distinct spellings avoid unique-collation collisions in MySQL/MariaDB.
        $names = ['Alpha', 'ALPHABET', 'Alpine', 'Beta', 'Éclair', 'Ömega', 'Σigma', 'İstanbul', 'percent%name', 'under_score', 'bang!name', 'query?name', 'back\\slash', 'trail ', 'many-middle-end', 'MiXeDStored', 'ÄMiXeD'];
        foreach ($names as $i => $name) {
            $this->insertPair($i + 1, $name, $i % 2 ? '2001:db8::7' : '192.0.2.7', $i, in_array($name, ['MiXeDStored', 'ÄMiXeD'], true));
        }
    }

    private function insertPair(int $id, string $name, ?string $ip, int $state, bool $rawKey = false): void
    {
        $key = $rawKey ? $name : strtolower($name);
        $this->connection->insert('registered_nicks', [
            'id' => $id, 'nickname' => $name, 'nickname_lower' => $key,
            'status' => NickStatus::cases()[$state % count(NickStatus::cases())]->value,
            'language' => 'en', 'private' => 0, 'msg_privmsg' => 0, 'last_connect_ip' => $ip,
        ]);
        $this->connection->insert('registered_channels', [
            'id' => $id, 'name' => '#' . $name, 'name_lower' => '#' . $key,
            'founder_nick_id' => 1, 'description' => 'Fixture', 'entrymsg' => '',
            'topic_lock' => 0, 'mlock_active' => 0, 'mlock' => '', 'mlock_params' => '{}', 'secure' => 0,
            'created_at' => '2026-09-27 00:00:00',
            'status' => ChannelStatus::cases()[$state % count(ChannelStatus::cases())]->value,
        ]);
    }

    private function seedCardinality(): void
    {
        $this->connection->beginTransaction();
        for ($i = 100; $i < 50100; ++$i) {
            $this->insertPair($i, sprintf('load%05d', $i), 12345 === $i ? '203.0.113.9' : null, 0);
        }
        $this->connection->commit();
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $result = [];
        foreach (['registered_nicks', 'registered_channels'] as $table) {
            $columns = array_keys($this->connection->createSchemaManager()->listTableColumns($table));
            $columns = array_values(array_diff($columns, ['list_search_key']));
            sort($columns);
            $sql = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ' ORDER BY id';
            $digest = hash_init('sha256');
            foreach ($this->connection->iterateAssociative($sql) as $row) {
                hash_update($digest, serialize($row));
            }
            $result[$table] = hash_final($digest);
        }

        return $result;
    }

    private function assertIndexesPresent(bool $present = true): void
    {
        $platform = $this->connection->getDatabasePlatform();
        foreach (['registered_nicks' => ['idx_registered_nicks_whoip', 'idx_registered_nicks_list_pattern'], 'registered_channels' => ['idx_registered_channels_list_pattern']] as $table => $indexes) {
            // Verify physical indexes directly, including backend-specific attributes.
            $sql = $platform instanceof SQLitePlatform
                ? "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?"
                : ($platform instanceof AbstractMySQLPlatform
                    ? 'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                    : 'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?');
            $actual = $this->connection->fetchFirstColumn($sql, [$table]);
            foreach ($indexes as $index) {
                if ($present) {
                    self::assertContains($index, $actual);
                } else {
                    self::assertNotContains($index, $actual);
                }
            }
        }
    }

    private function assertLegacyListEquivalence(): void
    {
        $patterns = ['Alpha', 'ALPHA', 'al*', '*a*', 'missing', 'Éclair', 'écl*', '*mega', 'Σ*', 'İ*', 'percent%name', 'under_score', 'bang!name', 'query?name', 'back\\slash', 'trail ', 'trail', 'many**end', 'mixedstored', 'MIXED*', 'Ämixed', 'ämixed', '*MiXeD*', '*', ''];
        foreach ([['registered_nicks', 'nickname_lower', new RegisteredNickDoctrineRepository($this->entityManager), ''], ['registered_channels', 'name_lower', new RegisteredChannelDoctrineRepository($this->entityManager), '#']] as [$table, $key, $repository, $prefix]) {
            foreach ($patterns as $pattern) {
                $pattern = $prefix . $pattern;
                $like = strtr(strtolower($pattern), ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']);
                $legacy = ' FROM ' . $table . " WHERE LOWER($key) LIKE ? ESCAPE '!'";
                $expectedCount = self::databaseInteger($this->connection->fetchOne('SELECT COUNT(*)' . $legacy, [$like]));
                self::assertSame($expectedCount, $repository->countByPattern($pattern), $table . ': ' . $pattern);
                foreach ([[0, 3], [3, 3], [99999, 3], [-1, 3], [0, 0]] as [$offset, $limit]) {
                    $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery('SELECT id' . $legacy . " ORDER BY $key ASC, id ASC", max(0, $limit), max(0, $offset));
                    $expected = array_map(self::databaseInteger(...), $this->connection->fetchFirstColumn($sql, [$like]));
                    $actual = array_map(static fn (RegisteredChannel|RegisteredNick $entity): int => $entity->getId(), $repository->searchByPattern($pattern, $offset, $limit));
                    self::assertSame($expected, $actual, $table . ': ' . $pattern . ' page ' . $offset);
                }
            }
        }
        $this->entityManager->clear();
    }

    private static function databaseInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        self::assertIsString($value);
        self::assertMatchesRegularExpression('/^[0-9]+$/', $value);

        return (int) $value;
    }

    private function assertWhoipEquivalence(): void
    {
        $repository = new RegisteredNickDoctrineRepository($this->entityManager);
        foreach (['192.0.2.7', '2001:db8::7', '192.0.2.254'] as $ip) {
            $expected = $this->connection->fetchFirstColumn('SELECT nickname FROM registered_nicks WHERE last_connect_ip = ? ORDER BY nickname_lower ASC, id ASC', [$ip]);
            self::assertSame($expected, $repository->findNicknamesByLastConnectIp($ip));
        }
    }

    private function refreshStatistics(): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::assertSame([], $this->connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE name IN ('sqlite_stat1', 'sqlite_stat4')"));
            $this->createdSqliteStatistics = true;
        }
        foreach (['registered_nicks', 'registered_channels'] as $table) {
            $this->connection->executeStatement(($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'ANALYZE TABLE ' : 'ANALYZE ') . $table);
        }
    }

    private function removeFixtureStatistics(): void
    {
        if (!$this->createdSqliteStatistics) {
            return;
        }
        // DBAL cannot introspect typeless SQLite statistics tables. Only remove the
        // two known internal tables created by this fixture's own ANALYZE operation.
        $this->connection->executeStatement('DROP TABLE IF EXISTS sqlite_stat1');
        $this->connection->executeStatement('DROP TABLE IF EXISTS sqlite_stat4');
        $this->createdSqliteStatistics = false;
    }

    private function assertSelectivePlans(): void
    {
        $this->logger->enabled = true;
        $nickRepository = new RegisteredNickDoctrineRepository($this->entityManager);
        $nickRepository->findNicknamesByLastConnectIp('203.0.113.9');
        foreach ([[$nickRepository, ''], [new RegisteredChannelDoctrineRepository($this->entityManager), '#']] as [$repository, $prefix]) {
            foreach (['load12345', 'load1234*'] as $pattern) {
                $repository->countByPattern($prefix . $pattern);
                $repository->searchByPattern($prefix . $pattern, 0, 50);
            }
        }
        $this->logger->enabled = false;
        self::assertCount(9, $this->logger->queries);
        foreach ($this->logger->queries as $query) {
            $platform = $this->connection->getDatabasePlatform();
            $explain = $platform instanceof SQLitePlatform ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';
            $rows = $this->connection->fetchAllAssociative($explain . $query['sql'], $query['params'], $query['types']);
            $plan = json_encode($rows, JSON_THROW_ON_ERROR);
            $index = str_contains($query['sql'], 'last_connect_ip =')
                ? 'idx_registered_nicks_whoip'
                : (str_contains($query['sql'], 'registered_channels') ? 'idx_registered_channels_list_pattern' : 'idx_registered_nicks_list_pattern');
            if ($platform instanceof AbstractMySQLPlatform) {
                self::assertSame($index, $rows[0]['key'], $plan);
                self::assertContains($rows[0]['type'], ['ref', 'range'], $plan);
            } else {
                self::assertStringContainsString($index, $plan);
                self::assertMatchesRegularExpression($platform instanceof SQLitePlatform ? '/SEARCH/' : '/Index (Only )?Scan|Bitmap Index Scan/', $plan);
            }
        }
    }
}
