<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Integration;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordMutationDoctrineStore;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\NickServ\Adapter\In\Event\NickProtectionSubscriber;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\In\NickServEvent;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\GuestNicknameGenerator;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\IdentifiedUserVhostSyncService;
use App\NickServ\Application\Service\NickProtectionService;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\UseCase\IssueMigrationRescueToken\IssueMigrationRescueTokenHandlerInterface;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentificationHandlerInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

use function array_keys;
use function in_array;
use function sprintf;

/**
 * Reproduces the UnrealUdb end-of-burst chain: an implicitly identified IRCop
 * gets an operclass written through the UDB mutation store while the burst
 * chunk still holds its preloaded NickServ accounts in the same EntityManager.
 */
#[CoversClass(UdbRecordMutationDoctrineStore::class)]
#[CoversClass(NickProtectionSubscriber::class)]
#[Group('integration')]
final class UdbOperclassBurstProtectionTest extends DoctrineIntegrationTestCase
{
    private const int USERS = 401;

    #[Test]
    public function burstWithAnIdentifiedOperclassIrcopCompletesEveryChunk(): void
    {
        $registeredAt = new \DateTimeImmutable('2026-01-01 00:00:00');
        $seenAt = new \DateTimeImmutable('2026-03-04 05:06:07');
        $repository = new RegisteredNickDoctrineRepository($this->entityManager, NegotiatedCaseMapping::network());
        for ($i = 1; $i <= self::USERS; ++$i) {
            $repository->save(RegisteredNick::createActive($this->nick($i), 'hash', null, 'en', $registeredAt));
        }
        $this->flushAndClear();

        $origin = new UdbStreamOrigin('002', '0123456789abcdef');
        $mutations = new UdbRecordMutationDoctrineStore($this->entityManager, new MockClock('2026-03-04 05:06:07 UTC'));
        $mutations->beginLocalStream($origin);
        $session = $this->createStub(UdbSessionStateInterface::class);
        $session->method('streamOrigin')->willReturn($origin);
        $writer = new UnrealUdbRecordWriter($session, $mutations);
        // The IRCop in every chunk receives an operclass, as IrcopOperclassApplier
        // does through UnrealUdbProtocolServiceActions::setUserOperclass().
        $operators = [$this->nick(1), $this->nick(201), $this->nick(401)];
        $publisher = new class($writer, $operators) implements NickServEventPublisher {
            /** @param list<string> $operators */
            public function __construct(private readonly UnrealUdbRecordWriter $writer, private readonly array $operators) {}

            public function publish(NickServEvent $event): void
            {
                if ($event instanceof NickIdentifiedEvent && in_array($event->nickname, $this->operators, true)) {
                    $this->writer->insert('N', $event->nickname . '::oper', 'netadmin');
                }
            }
        };

        $burstState = new BurstState();
        for ($i = 1; $i <= self::USERS; ++$i) {
            $burstState->addPending(new NetworkUser(sprintf('002AAA%03d', $i), $this->nick($i), 'ident', 'host', 'cloak', 'ip', isIdentified: true, serverSid: '002'));
        }
        $subscriber = $this->subscriber($repository, $burstState, $publisher, $seenAt);

        $subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('002'));

        $this->entityManager->clear();
        $records = new UdbRecordDoctrineRepository($this->entityManager)->recordsByBlock('N');
        foreach ($operators as $operator) {
            self::assertSame('netadmin', $records[$operator . '::oper']->value ?? null);
        }
        $rows = $this->entityManager->getConnection()->fetchFirstColumn('SELECT nickname FROM registered_nicks WHERE last_seen_at = ? ORDER BY id', [$seenAt->format('Y-m-d H:i:s')]);
        self::assertCount(self::USERS, $rows);
        self::assertSame($this->nick(self::USERS), $rows[self::USERS - 1]);
    }

    #[Test]
    public function aFailingChunkIsLoggedAndDoesNotSkipLaterChunks(): void
    {
        $registeredAt = new \DateTimeImmutable('2026-01-01 00:00:00');
        $seenAt = new \DateTimeImmutable('2026-03-04 05:06:07');
        $repository = new class($this->entityManager, NegotiatedCaseMapping::network()) extends RegisteredNickDoctrineRepository {
            public int $calls = 0;

            public function saveActivityBatch(array $nicks): void
            {
                if (1 === ++$this->calls) {
                    throw new \RuntimeException('first chunk storage failure');
                }
                parent::saveActivityBatch($nicks);
            }
        };
        $burstState = new BurstState();
        for ($i = 1; $i <= self::USERS; ++$i) {
            $repository->save(RegisteredNick::createActive($this->nick($i), 'hash', null, 'en', $registeredAt));
            $burstState->addPending(new NetworkUser(sprintf('002AAA%03d', $i), $this->nick($i), 'ident', 'host', 'cloak', 'ip', isIdentified: true, serverSid: '002'));
        }
        $this->flushAndClear();
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $subscriber = $this->subscriber($repository, $burstState, $this->createStub(NickServEventPublisher::class), $seenAt, $logger);

        $subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('002'));

        self::assertSame(3, $repository->calls);
        $connection = $this->entityManager->getConnection();
        // The failed chunk's accounts stay managed and dirty, so the next chunk's
        // transaction flush still persists them: no activity is lost.
        $seen = $connection->fetchFirstColumn('SELECT nickname FROM registered_nicks WHERE last_seen_at = ? ORDER BY id', [$seenAt->format('Y-m-d H:i:s')]);
        self::assertCount(self::USERS, $seen);
        self::assertSame($this->nick(self::USERS), $seen[self::USERS - 1]);
        self::assertCount(0, $connection->fetchFirstColumn('SELECT 1 FROM registered_nicks WHERE last_seen_at = ?', [$registeredAt->format('Y-m-d H:i:s')]));
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame('NickServ burst protection chunk failed; continuing with the next chunk.', $logger->records[0]['message']);
        $context = $logger->records[0]['context'];
        self::assertSame(['chunk', 'users', 'exception'], array_keys($context));
        self::assertSame([1, 200], [$context['chunk'], $context['users']]);
        self::assertInstanceOf(\RuntimeException::class, $context['exception']);
        self::assertSame('first chunk storage failure', $context['exception']->getMessage());
    }

    private function nick(int $number): string
    {
        return sprintf('User%03d', $number);
    }

    private function subscriber(RegisteredNickDoctrineRepository $repository, BurstState $burstState, NickServEventPublisher $publisher, \DateTimeImmutable $now, ?LoggerInterface $logger = null): NickProtectionSubscriber
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $policy = $this->createStub(NickChangeIdentificationPolicy::class);
        $mode = $this->createStub(NicknameAuthenticationModeQuery::class);
        $mode->method('current')->willReturn(NicknameAuthenticationMode::ServiceCommand);
        $actions = $this->createStub(NickNetworkActions::class);
        $protection = new NickProtectionService(
            $repository,
            $this->createStub(NickNetworkUserLookup::class),
            $actions,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $publisher,
            $this->createStub(ForbiddenNickService::class),
            $policy,
            $this->createStub(GuestNicknameGenerator::class),
            $this->createStub(IssueMigrationRescueTokenHandlerInterface::class),
            $mode,
            NegotiatedCaseMapping::nickServ(),
        );
        $vhostSync = new IdentifiedUserVhostSyncService(
            $repository,
            $actions,
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $policy,
        );

        return new NickProtectionSubscriber(
            $protection,
            $vhostSync,
            $burstState,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            logger: $logger ?? new NullLogger(),
        );
    }
}
