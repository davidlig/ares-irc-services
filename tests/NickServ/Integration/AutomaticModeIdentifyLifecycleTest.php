<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Integration;

use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\LocalUserModeSync;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\ServiceBridge\CoreNetworkUserLookupAdapter;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\LocalUserQuitSyncPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use App\NickServ\Adapter\In\Event\NickAutomaticModesSubscriber;
use App\NickServ\Adapter\In\Irc\Bot\NickServBot;
use App\NickServ\Adapter\In\Irc\Command\IdentifyCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\Out\Event\SymfonyIdentifyEventPublisher;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\IdentifyFailedAttemptRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingNickRestoreRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Adapter\Out\User\IrcNickNetworkUserLookup;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\AutomaticModeActions;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\Service\AutomaticModeSessionService;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\Service\NickServClientKeyResolver;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\UseCase\Identify\IdentifyNickHandler;
use App\NickServ\Application\UseCase\IssueMigrationRescueToken\IssueMigrationRescueTokenHandlerInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\AutomaticModeProfile;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversNothing]
final class AutomaticModeIdentifyLifecycleTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function testGuestIdentifyRestoresNicknameThenAppliesSavedModesWithoutIrcdEcho(): void
    {
        $now = new DateTimeImmutable('2026-11-01T18:00:00Z');
        $registeredNicks = new RegisteredNickDoctrineRepository($this->entityManager);
        $account = RegisteredNick::createActive('Pedro', 'test-hash', null, 'en', $now);
        $account->changeAutomaticModes(new AutomaticModeProfile(['bot']));
        $registeredNicks->save($account);

        $networkUsers = new InMemoryNetworkUserRepository();
        $networkUsers->add(new NetworkUser(
            new Uid('001USER'),
            new Nick('Guest123'),
            new Ident('ident'),
            'host',
            'cloak',
            '*',
            '',
            $now,
            'Guest user',
            '001',
            '*',
        ));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new NetworkStateSubscriber(
            $networkUsers,
            new InMemoryChannelRepository(),
        ));
        $networkLookup = new CoreNetworkUserLookupAdapter($networkUsers);
        $sessions = new IdentifiedSessionRegistry();
        $nickNetworkLookup = new IrcNickNetworkUserLookup($networkLookup);
        $modeChanges = [];
        $outboundCalls = [];
        $automaticModeActions = $this->createStub(AutomaticModeActions::class);
        $automaticModeActions->method('usesNativeProfiles')->willReturn(false);
        $automaticModeActions->method('change')->willReturnCallback(static function (string $uid, string $delta) use (&$modeChanges, $nickNetworkLookup): bool {
            self::assertSame('+' === $delta[0], $nickNetworkLookup->findByUid($uid)?->isIdentified);
            $modeChanges[] = $delta;

            return true;
        });
        $modeCatalog = $this->createStub(AutomaticModeCatalog::class);
        $modeCatalog->method('available')->willReturn(['bot' => 'B']);
        $automaticModes = new AutomaticModeSessionService(
            $sessions,
            $registeredNicks,
            $nickNetworkLookup,
            $modeCatalog,
            $automaticModeActions,
        );
        $dispatcher->addSubscriber(new NickAutomaticModesSubscriber(
            $automaticModes,
            $sessions,
            new NickHistoryService($this->createStub(NickHistoryRepositoryInterface::class)),
            $nickNetworkLookup,
        ));
        $preSyncState = [];
        $dispatcher->addListener(NickIdentifiedEvent::class, static function (NickIdentifiedEvent $event) use (&$preSyncState, &$modeChanges, $networkLookup): void {
            $user = $networkLookup->findByUid($event->uid);
            $preSyncState[] = [$user?->nick, $user?->isIdentified, $modeChanges];
        }, -10);

        $eventBus = new class($dispatcher) implements EventBusInterface {
            public function __construct(private readonly EventDispatcher $dispatcher) {}

            public function dispatch(object $event): void
            {
                $this->dispatcher->dispatch($event);
            }
        };
        $identifyEvents = new SymfonyIdentifyEventPublisher($eventBus);
        $passwordHasher = $this->createStub(PasswordHasher::class);
        $passwordHasher->method('verify')->willReturn(true);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $identifyHandler = new IdentifyNickHandler(
            $registeredNicks,
            $sessions,
            new IdentifyFailedAttemptRegistry(),
            $identifyEvents,
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $passwordHasher,
            $clock,
            $this->createStub(IssueMigrationRescueTokenHandlerInterface::class),
            5,
            900,
            1800,
        );

        $protocolActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $protocolActions->expects(self::once())
            ->method('forceNick')
            ->with('001', '001USER', 'Pedro')
            ->willReturnCallback(static function () use (&$outboundCalls): void { $outboundCalls[] = 'restore-nickname'; });
        $protocolActions->expects(self::once())
            ->method('setUserAccount')
            ->with('001', '001USER', 'Pedro')
            ->willReturnCallback(static function () use (&$outboundCalls): void { $outboundCalls[] = 'identify-account'; });
        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($protocolActions);
        $connection = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn($protocolModule);
        $connection->method('getServerSid')->willReturn('001');
        $pendingRegistry = new PendingNickRestoreRegistry();
        $serviceUidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $notifier = new NickServBot(
            $connection,
            $networkLookup,
            $this->createStub(SendNoticePort::class),
            $pendingRegistry,
            new LocalUserModeSync($dispatcher),
            $serviceUidGenerator,
            'services.example.test',
            $this->createStub(LocalUserQuitSyncPort::class),
        );

        $authenticationMode = $this->createStub(NicknameAuthenticationModeQuery::class);
        $authenticationMode->method('current')->willReturn(NicknameAuthenticationMode::ServiceCommand);
        $command = new IdentifyCommand(
            $identifyHandler,
            $networkLookup,
            $pendingRegistry,
            new NickServClientKeyResolver(),
            $authenticationMode,
            $automaticModes,
        );
        $registry = new NickServCommandRegistry([$command]);
        $replies = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key) use (&$replies): string {
            $replies[] = $key;

            return $key;
        });
        $context = new NickServContext(
            new SenderView('001USER', 'Guest123', 'ident', 'host', 'cloak', 'ip', serverSid: '001'),
            null,
            'IDENTIFY',
            ['Pedro', 'password'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([]),
        );

        $registry->find('IDENTIFY')?->execute($context);
        $registry->find('IDENTIFY')?->execute($context);

        self::assertSame([['Guest123', false, []]], $preSyncState);
        self::assertSame(['restore-nickname', 'identify-account'], $outboundCalls);
        self::assertSame(['identify.success', 'identify.already_identified'], $replies);
        self::assertTrue($networkLookup->findByUid('001USER')?->isIdentified);
        self::assertSame('Guest123', $networkLookup->findByUid('001USER')->nick);
        self::assertTrue($pendingRegistry->peek('001USER'));
        $changesBeforeIdentityLoss = $modeChanges;
        self::assertSame(['+B'], $changesBeforeIdentityLoss);

        $dispatcher->addSubscriber(new PublishedIrcEventBridge($dispatcher));
        $dispatcher->dispatch(new UserModeChangedEvent(new Uid('001USER'), '+B-r+r'));
        self::assertTrue($networkLookup->findByUid('001USER')->isIdentified);
        $changesAfterCombinedDelta = $modeChanges;
        self::assertSame(['+B'], $changesAfterCombinedDelta);
        $dispatcher->dispatch(new UserModeChangedEvent(new Uid('001USER'), '+i-r'));
        self::assertFalse($networkLookup->findByUid('001USER')->isIdentified);
        self::assertSame('Guest123', $networkLookup->findByUid('001USER')->nick);
        self::assertSame(['+B', '-B'], $modeChanges);
    }
}
