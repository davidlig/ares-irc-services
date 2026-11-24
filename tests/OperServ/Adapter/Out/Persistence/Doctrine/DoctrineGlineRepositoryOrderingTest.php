<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineGlineRepository;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\Tests\Bootstrap\Persistence\QueryCaptureLogger;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function sys_get_temp_dir;

/** GLINE LIST and GLINE DEL <index> must address rows through one total order. */
#[CoversClass(DoctrineGlineRepository::class)]
#[Group('integration')]
final class DoctrineGlineRepositoryOrderingTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function listAndIndexShareOneNewestFirstOrderForGlinesCreatedInTheSameSecond(): void
    {
        $repository = new DoctrineGlineRepository($this->entityManager);
        $sameSecond = new \DateTimeImmutable('2026-05-01 10:00:00');
        foreach (['*@a.test', '*@b.test', '*@c.test', '*@d.test'] as $mask) {
            $repository->save($mask, null, 'abuse', $sameSecond, null);
        }
        $this->entityManager->clear();

        $listed = self::masks($repository->findAll());

        self::assertSame(['*@d.test', '*@c.test', '*@b.test', '*@a.test'], $listed);
        self::assertSame($listed, self::masks($repository->findByMaskPattern('.test')));
        foreach ($listed as $index => $mask) {
            self::assertSame($mask, $repository->findByIndex($index)?->mask);
        }
        self::assertNull($repository->findByIndex(4));
    }

    #[Test]
    public function distinctTimestampsStillListNewestFirstBeforeInsertionOrder(): void
    {
        $repository = new DoctrineGlineRepository($this->entityManager);
        $repository->save('*@middle.test', null, 'abuse', new \DateTimeImmutable('2026-05-01 10:00:02'), null);
        $repository->save('*@oldest.test', null, 'abuse', new \DateTimeImmutable('2026-05-01 10:00:01'), null);
        $repository->save('*@newest.test', null, 'abuse', new \DateTimeImmutable('2026-05-01 10:00:03'), null);
        $this->entityManager->clear();

        $expected = ['*@newest.test', '*@middle.test', '*@oldest.test'];

        self::assertSame($expected, self::masks($repository->findAll()));
        self::assertSame($expected, self::masks($repository->findByMaskPattern('.test')));
        self::assertSame($expected, self::masks($repository->findActiveAt(new \DateTimeImmutable('2026-05-01 11:00:00'))));
        foreach ($expected as $index => $mask) {
            self::assertSame($mask, $repository->findByIndex($index)?->mask);
        }
    }

    #[Test]
    public function everyListAndIndexQueryBreaksCreatedAtTiesById(): void
    {
        $logger = new QueryCaptureLogger();
        $repository = new DoctrineGlineRepository($this->capturingEntityManager($logger));
        $logger->enabled = true;

        $repository->findAll();
        $repository->findByIndex(1);
        $repository->findByMaskPattern('host');
        $repository->findActiveAt(new \DateTimeImmutable('2026-05-01 10:00:00'));

        self::assertCount(4, $logger->queries);
        foreach ($logger->queries as $query) {
            self::assertMatchesRegularExpression('/ORDER BY \w+\.created_at DESC, \w+\.id DESC(?: |$)/', $query['sql']);
        }
    }

    #[Test]
    public function expiredEntriesArePagedByExpiryThenIdAfterTheKeysetCursor(): void
    {
        $repository = new DoctrineGlineRepository($this->entityManager);
        $createdAt = new \DateTimeImmutable('2026-05-01 07:00:00');
        $repository->save('*@first.test', null, 'abuse', $createdAt, new \DateTimeImmutable('2026-05-01 08:30:00'));
        $repository->save('*@active.test', null, 'abuse', $createdAt, new \DateTimeImmutable('2026-05-01 12:00:00'));
        $repository->save('*@earliest.test', null, 'abuse', $createdAt, new \DateTimeImmutable('2026-05-01 08:00:00'));
        $repository->save('*@tied.test', null, 'abuse', $createdAt, new \DateTimeImmutable('2026-05-01 08:30:00'));
        $repository->save('*@permanent.test', null, 'abuse', $createdAt, null);
        $this->entityManager->clear();
        $now = new \DateTimeImmutable('2026-05-01 10:00:00');

        $firstPage = $repository->findExpiredAt($now, null, 2);
        $secondPage = $repository->findExpiredAt($now, $firstPage[1], 2);

        self::assertSame(['*@earliest.test', '*@first.test', '*@tied.test'], self::masks($repository->findExpiredAt($now)));
        self::assertSame(['*@earliest.test', '*@first.test'], self::masks($firstPage));
        self::assertSame(['*@tied.test'], self::masks($secondPage));
        self::assertSame([], $repository->findExpiredAt($now, $secondPage[0], 2));
    }

    #[Test]
    public function rejectsACursorThatIsNotAPersistedExpiredEntry(): void
    {
        $repository = new DoctrineGlineRepository($this->entityManager);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GLINE expiry cursor must be a persisted expired entry.');

        $repository->findExpiredAt(new \DateTimeImmutable(), new GlineEntry('*@host.test', null, null, new \DateTimeImmutable(), null, 7), 2);
    }

    /**
     * @param list<GlineEntry> $entries
     *
     * @return list<string>
     */
    private static function masks(array $entries): array
    {
        return array_map(static fn (GlineEntry $entry): string => $entry->mask, $entries);
    }

    private function capturingEntityManager(QueryCaptureLogger $logger): EntityManager
    {
        $config = new Configuration();
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('GlineOrderProxies');
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../../../../../config/doctrine/operserv' => 'App\OperServ\Domain',
        ]));
        $config->setMiddlewares([new Middleware($logger)]);
        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
