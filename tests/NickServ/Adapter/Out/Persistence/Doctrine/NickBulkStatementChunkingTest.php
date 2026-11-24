<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\NickAccountBatchDoctrineQuery;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Adapter\Out\User\NickAccountResolver;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Bootstrap\Persistence\QueryCaptureLogger;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function range;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function sys_get_temp_dir;

/**
 * Bulk nickname statements must bind a bounded number of parameters however
 * many nicknames a burst, a large channel or a netsplit supplies, and never
 * more than SQLite's historic limit of 999.
 */
#[CoversClass(RegisteredNickDoctrineRepository::class)]
#[CoversClass(NickAccountBatchDoctrineQuery::class)]
#[CoversClass(NickAccountResolver::class)]
#[Group('integration')]
final class NickBulkStatementChunkingTest extends TestCase
{
    private const int NICKS = 2500;

    /** SQLite's historic bound-parameter limit (SQLITE_MAX_VARIABLE_NUMBER before 3.32). */
    private const int MAX_PARAMETERS = 999;

    private EntityManager $em;

    private Connection $connection;

    private QueryCaptureLogger $logger;

    #[Test]
    public function repositoryLookupIsChunkedAndReturnsEveryMatch(): void
    {
        $repository = new RegisteredNickDoctrineRepository($this->em, NegotiatedCaseMapping::network());

        $this->logger->enabled = true;
        $accounts = $repository->findByNicks($this->requestedNames());
        $this->logger->enabled = false;

        self::assertSame($this->resolvedNames(), $this->sorted(array_keys($accounts)));
        self::assertSame($this->expectedNames(), $this->sorted(array_values(array_unique(array_map(static fn (RegisteredNick $nick): string => $nick->getNickname(), $accounts)))));
        $this->assertBoundedStatements('SELECT', 3, self::MAX_PARAMETERS);
    }

    #[Test]
    public function batchAccountQueryIsChunkedAndKeepsLowercaseKeys(): void
    {
        $query = new NickAccountBatchDoctrineQuery($this->em, NegotiatedCaseMapping::network());

        $this->logger->enabled = true;
        $accounts = $query->findAccountsByNicks($this->requestedNames());
        $this->logger->enabled = false;

        self::assertSame($this->resolvedNames(), $this->sorted(array_keys($accounts)));
        self::assertSame('Nick0001', $accounts['NICK0001']->nickname);
        self::assertSame(1, $accounts['Nick0001']->id);
        $this->assertBoundedStatements('SELECT', 3, self::MAX_PARAMETERS);
    }

    #[Test]
    public function accountResolverUsesTheChunkedLookupAndKeepsLowercaseKeys(): void
    {
        $resolver = new NickAccountResolver(new RegisteredNickDoctrineRepository($this->em, NegotiatedCaseMapping::network()));

        $this->logger->enabled = true;
        $accounts = $resolver->findAccountsByNicks($this->requestedNames());
        $this->logger->enabled = false;

        self::assertSame($this->resolvedNames(), $this->sorted(array_keys($accounts)));
        self::assertSame('Nick2500', $accounts['NICK2500']->nickname);
        $this->assertBoundedStatements('SELECT', 3, self::MAX_PARAMETERS);
    }

    #[Test]
    public function nicknameByIdLookupIsChunkedAndReturnsEveryMatch(): void
    {
        $repository = new RegisteredNickDoctrineRepository($this->em, NegotiatedCaseMapping::network());

        $this->logger->enabled = true;
        $nicknames = $repository->findNicknamesByIds([...range(1, self::NICKS), self::NICKS + 1]);
        $this->logger->enabled = false;

        self::assertSame(range(1, self::NICKS), $this->sortedIds(array_keys($nicknames)));
        self::assertSame('Nick0001', $nicknames[1]);
        self::assertSame('Nick2500', $nicknames[self::NICKS]);
        $this->assertBoundedStatements('SELECT', 3, self::MAX_PARAMETERS);
    }

    #[Test]
    public function activityBatchUpdateIsChunkedAndPersistsEveryTimestamp(): void
    {
        $repository = new RegisteredNickDoctrineRepository($this->em, NegotiatedCaseMapping::network());
        $accounts = array_values($repository->findByNicks($this->expectedNames()));
        $seenAt = new \DateTimeImmutable('2026-05-06 07:08:09');
        foreach ($accounts as $account) {
            $account->markSeen($seenAt);
        }

        $this->logger->enabled = true;
        $repository->saveActivityBatch($accounts);
        $this->logger->enabled = false;

        $this->assertBoundedStatements('UPDATE', 9, self::MAX_PARAMETERS);
        self::assertCount(self::NICKS, $this->connection->fetchFirstColumn('SELECT 1 FROM registered_nicks WHERE last_seen_at = ?', [$seenAt->format('Y-m-d H:i:s')]));
        self::assertTrue($this->em->contains($accounts[0]));
    }

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('NickChunkingProxies');
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../../../../../config/doctrine/nickserv' => 'App\NickServ\Domain\Entity',
        ]));
        $this->logger = new QueryCaptureLogger();
        $config->setMiddlewares([new Middleware($this->logger)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($this->connection, $config);
        new SchemaTool($this->em)->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        foreach (range(1, self::NICKS) as $number) {
            $name = sprintf('Nick%04d', $number);
            $this->connection->insert('registered_nicks', ['id' => $number, 'nickname' => $name, 'nickname_lower' => strtolower($name), 'status' => 'registered', 'language' => 'en', 'private' => 0, 'msg_privmsg' => 0, 'no_expire' => 0, 'registered_at' => '2026-01-01 00:00:00', 'last_seen_at' => '2026-01-01 00:00:00']);
        }
    }

    protected function tearDown(): void
    {
        $this->em->close();
        $this->connection->close();
    }

    /** @return list<string> */
    private function requestedNames(): array
    {
        $names = [];
        foreach ($this->expectedNames() as $name) {
            $names[] = strtoupper($name);
            $names[] = $name;
        }
        $names[] = 'MissingNick';

        return $names;
    }

    /** @return list<string> every requested spelling that resolves to a registration, sorted */
    private function resolvedNames(): array
    {
        return $this->sorted(array_values(array_filter($this->requestedNames(), static fn (string $name): bool => 'MissingNick' !== $name)));
    }

    /** @return list<string> */
    private function expectedNames(): array
    {
        return array_map(static fn (int $number): string => sprintf('Nick%04d', $number), range(1, self::NICKS));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private function sortedIds(array $values): array
    {
        sort($values);

        return $values;
    }

    private function assertBoundedStatements(string $verb, int $expectedStatements, int $maxParameters): void
    {
        $statements = array_values(array_filter(
            $this->logger->queries,
            static fn (array $query): bool => str_starts_with($query['sql'], $verb) && str_contains($query['sql'], 'registered_nicks'),
        ));
        self::assertCount($expectedStatements, $statements);
        foreach ($statements as $statement) {
            self::assertLessThanOrEqual($maxParameters, count($statement['params']));
        }
    }
}
