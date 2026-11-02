<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Integration;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\PasswordMigrationStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSuspendReasonResolver;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbNickSyncSubscriber;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\AutomaticModeDelivery;
use App\Irc\Application\Port\In\AutomaticModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\SasetCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\Event\SymfonyNickServEventPublisher;
use App\NickServ\Adapter\Out\Event\SymfonyRegistrationEventPublisher;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\InMemoryRescueAttemptLimiter;
use App\NickServ\Adapter\Out\InMemory\InMemoryTemporaryRescueTokenStore;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRescueCredentialPersistence;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickHistoryDoctrineRepository;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Adapter\Out\User\NickProjectionResolver;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionExemption;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\UseCase\Set\SetAutomaticModesHandler;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandlerInterface;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandler;
use App\NickServ\Application\UseCase\Verify\VerifyNickOutcome;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

use function str_ends_with;

/** Controlled native persistence boundary; application, SQL and projection paths stay real. */
final class AutomaticModeVerificationRecordWriter implements UdbRecordWriterInterface
{
    /** @var array<string, string> */
    public array $records = [];

    public bool $rejectModes = false;

    public bool $throwModes = false;

    public function insert(string $block, string $path, string $value): bool
    {
        if (!$this->accept($path)) {
            return false;
        }
        $this->records[$path] = $value;

        return true;
    }

    public function delete(string $block, string $path): bool
    {
        if (!$this->accept($path)) {
            return false;
        }
        unset($this->records[$path]);

        return true;
    }

    private function accept(string $path): bool
    {
        if (str_ends_with($path, '::modes')) {
            if ($this->throwModes) {
                throw new RuntimeException('Synthetic native projection failure.');
            }

            return !$this->rejectModes;
        }

        return true;
    }
}

#[CoversNothing]
final class AutomaticModeVerificationLifecycleTest extends DoctrineIntegrationTestCase
{
    private const string PASSWORD_HASH = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';

    /**
     * @param list<string>               $requests
     * @param array<string, string>|null $supported
     */
    #[DataProvider('profiles')]
    public function testPendingPreferenceThenRealVerificationConvergesWithFullNativeExport(
        array $requests,
        ?array $supported,
        ?string $expectedModes,
        bool $rejectModes,
        bool $throwModes,
    ): void {
        $now = new DateTimeImmutable('2026-11-01T18:00:00Z');
        $repository = new RegisteredNickDoctrineRepository($this->entityManager);
        $account = RegisteredNick::createPending('Ares', self::PASSWORD_HASH, null, 'en', $now->modify('+1 hour'), $now);
        $account->changeVhost('ares.example.test');
        $repository->save($account);
        $accountId = $account->getId();
        $projection = new NickProjectionResolver($repository);
        $operators = $this->createStub(OperatorNetworkProjectionQuery::class);
        $support = $this->createStub(AutomaticModeSupport::class);
        $support->method('getAutomaticModes')->willReturn($supported);
        $native = false;
        $delivery = $this->createStub(AutomaticModeDelivery::class);
        $delivery->method('usesNativeProfiles')->willReturnCallback(static function () use (&$native): bool { return $native; });
        $exporter = new UdbRecordExporter(
            $projection,
            $this->createStub(ChannelProjectionQuery::class),
            $operators,
            $this->createStub(GlineProjectionQuery::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            new UdbChannelSuspendReasonResolver($this->createStub(TranslatorInterface::class), 'ChanServ'),
            automaticModeSupport: $support,
            automaticModeDelivery: $delivery,
        );
        $writer = new AutomaticModeVerificationRecordWriter();
        $writer->records['Ares::modes'] = '+D';
        $migration = $this->createMock(PasswordMigrationStateInterface::class);
        $migration->expects(self::once())->method('markAsMigrated')->with('Ares');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($rejectModes || $throwModes ? self::once() : self::never())->method('warning');
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UdbNickSyncSubscriber($writer, $projection, $migration, $operators, $exporter, $this->createStub(UdbSessionStateInterface::class), $logger));
        $bus = new class($dispatcher) implements EventBusInterface {
            public function __construct(private readonly EventDispatcher $dispatcher) {}

            public function dispatch(object $event): void
            {
                $this->dispatcher->dispatch($event);
            }
        };
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $catalog = $this->createStub(AutomaticModeCatalog::class);
        // This also permits a pending preference created on another protocol.
        $catalog->method('available')->willReturn(['bot' => 'B', 'strip-formatting' => 'S']);
        $setter = new SetAutomaticModesHandler(
            $repository,
            new NickTargetValidator($this->createStub(NickProtectionExemption::class), $repository),
            $catalog,
            $this->createStub(NickNetworkUserLookup::class),
            new SymfonyNickServEventPublisher($bus),
            $clock,
        );
        $otherSettings = $this->createMock(SetNickSettingHandlerInterface::class);
        $otherSettings->expects(self::never())->method('handle');
        $command = new SasetCommand($otherSettings, $setter);
        foreach ($requests as $modes) {
            $args = ['Ares', 'MODES'];
            if ('' !== $modes) {
                $args[] = $modes;
            }
            self::assertTrue($command->execute($this->context($args))->success);
        }
        self::assertTrue($account->isPending());
        self::assertArrayNotHasKey('Ares::modes', $writer->records);
        self::assertSame([], $exporter->allNickRecords());
        $storedIntentions = $account->getAutomaticModes()->intentions;
        $this->entityManager->clear();
        self::assertSame($storedIntentions, $repository->findById($accountId)?->getAutomaticModes()->intentions);

        $codec = new UuidRescueTokenCodec();
        $token = '12345678-1234-4234-8234-123456789abc';
        $tokenHash = $codec->hash($token);
        self::assertNotNull($tokenHash);
        $temporary = new InMemoryTemporaryRescueTokenStore();
        $temporary->replace($accountId, $tokenHash, $now->modify('+1 hour'));
        $sessions = new IdentifiedSessionRegistry();
        $authentication = $this->createStub(NicknameAuthenticationModeQuery::class);
        $authentication->method('current')->willReturn(NicknameAuthenticationMode::NativeNick);
        $history = new NickHistoryDoctrineRepository($this->entityManager);
        $verify = new VerifyNickHandler($repository, $codec, $temporary, new InMemoryRescueAttemptLimiter(), new DoctrineRescueCredentialPersistence($this->entityManager), $sessions, $clock, $authentication, new SymfonyRegistrationEventPublisher($bus), new NickHistoryService($history));
        $laterListenerRan = false;
        $dispatcher->addListener(NickPasswordHashAvailable::class, static function () use (&$laterListenerRan): void { $laterListenerRan = true; }, -100);
        $native = true;
        $writer->rejectModes = $rejectModes;
        $writer->throwModes = $throwModes;
        self::assertSame(VerifyNickOutcome::SuccessNativeAuthenticationRequired, $verify->handle(new VerifyNick('Ares', $token, 'ARESUID', 'Ares', '192.0.2.1'))->outcome);
        self::assertTrue($laterListenerRan);
        self::assertNull($sessions->findNick('ARESUID'));
        self::assertSame('registered', $this->entityManager->getConnection()->fetchOne('SELECT status FROM registered_nicks WHERE id = ?', [$accountId]));
        self::assertSame($tokenHash, $this->entityManager->getConnection()->fetchOne('SELECT token FROM registered_nicks WHERE id = ?', [$accountId]));
        self::assertSame($storedIntentions, $repository->findById($accountId)->getAutomaticModes()->intentions);
        self::assertSame(1, $history->countByNickId($accountId));
        $full = $exporter->allNickRecords();
        self::assertSame($expectedModes, $this->automaticModeRecord($full));
        self::assertSame('crypt:' . self::PASSWORD_HASH, $writer->records['Ares::pass'] ?? null);
        self::assertSame('ares.example.test', $writer->records['Ares::vhost'] ?? null);
        if ($rejectModes || $throwModes) {
            self::assertArrayNotHasKey('Ares::modes', $writer->records);
        } else {
            self::assertSame($full, $writer->records);
        }
    }

    /** @return iterable<string, array{list<string>, array<string, string>|null, string|null, bool, bool}> */
    public static function profiles(): iterable
    {
        yield 'pending B then activation' => [['B'], ['bot' => 'B'], '+B', false, false];
        yield 'cleared while pending' => [['B', ''], ['bot' => 'B'], null, false, false];
        yield 'incompatible prior protocol intention remains dormant' => [['S'], ['bot' => 'B'], null, false, false];
        yield 'known native capability excludes stored B' => [['B'], [], null, false, false];
        yield 'unknown inventory preserves compatible projection' => [['B'], null, '+B', false, false];
        yield 'native mode write rejected after activation' => [['B'], ['bot' => 'B'], '+B', true, false];
        yield 'native mode write throws after activation' => [['B'], ['bot' => 'B'], '+B', false, true];
    }

    /** @param list<string> $args */
    private function context(array $args): NickServContext
    {
        return new NickServContext(
            new SenderView('ADMINUID', 'Admin', 'id', 'host', 'cloak', '*', true, true, '001'),
            null,
            'SASET',
            $args,
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([]),
        );
    }

    /** @param array<string, string> $records */
    private function automaticModeRecord(array $records): ?string
    {
        return $records['Ares::modes'] ?? null;
    }
}
