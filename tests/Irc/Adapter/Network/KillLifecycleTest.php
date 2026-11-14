<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Network\ChannelSyncCompletedRegistry;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\LocalUserQuitSync;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolServiceActions;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneProtocolServiceActions;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolServiceActions;
use App\Irc\Application\Port\In as IrcPort;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use App\NickServ\Adapter\In\Event\NickProtectionSubscriber;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Application\Port\Out as NickPort;
use App\NickServ\Application\Service as NickService;
use App\NickServ\Application\UseCase\IssueMigrationRescueToken\IssueMigrationRescueTokenHandlerInterface;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentificationHandlerInterface;
use App\OperServ\Adapter\Out\Irc\ActiveConnectionKillNetworkUser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function hash;
use function serialize;

#[CoversNothing]
final class KillLifecycleTest extends TestCase
{
    #[Test]
    public function inboundAndServiceKillCleanTheWholeLifecycleOnce(): void
    {
        $cases = [['inspircd', 'quit']];
        foreach (['inspircd', 'standalone', 'udb'] as $protocol) {
            foreach (['inbound-uid', 'inbound-nick', 'service-direct', 'service-operserv'] as $mode) {
                $cases[] = [$protocol, $mode];
            }
        }

        foreach ($cases as [$protocol, $mode]) {
            $label = $protocol . '/' . $mode;
            $users = new InMemoryNetworkUserRepository();
            $channels = new InMemoryChannelRepository();
            $events = new EventDispatcher();
            $identified = new IdentifiedSessionRegistry();
            $languages = new SessionLanguageRegistry();
            $repo = $this->createStub(NickPort\RegisteredNickRepositoryInterface::class);
            $actions = $this->createStub(NickPort\NickNetworkActions::class);
            $policy = $this->createStub(NickPort\NickChangeIdentificationPolicy::class);
            $burst = new NickService\BurstState();
            $protection = new NickService\NickProtectionService(
                $repo,
                $this->createStub(NickPort\NickNetworkUserLookup::class),
                $actions,
                $burst,
                $identified,
                $languages,
                $this->createStub(NickPort\PendingNickRestoreRegistryInterface::class),
                $this->createStub(NickPort\NickProtectionNotifier::class),
                $this->createStub(NickPort\NickServEventPublisher::class),
                $this->createStub(NickService\ForbiddenNickService::class),
                $policy,
                $this->createStub(NickPort\GuestNicknameGenerator::class),
                $this->createStub(IssueMigrationRescueTokenHandlerInterface::class),
                $this->createStub(NickPort\NicknameAuthenticationModeQuery::class),
            );
            $vhost = new NickService\IdentifiedUserVhostSyncService($repo, $actions, new NickService\VhostDisplayResolver(), $this->createStub(NickPort\ForcedVhostCheckerInterface::class), $policy);
            $clock = $this->createStub(NickPort\Clock::class);
            $clock->method('now')->willReturn(new DateTimeImmutable());
            $registry = new ChannelSyncCompletedRegistry();
            $events->addSubscriber(new NetworkStateSubscriber($users, $channels, $registry));
            $events->addSubscriber(new NetworkEventEnricher($channels, $users, $events, $this->createStub(IrcPort\SkipIdentifiedModeStripRegistry::class), $this->createStub(IrcPort\ActiveChannelModeSupportProviderInterface::class), $this->createStub(IrcPort\ActiveProtocolModuleHolderInterface::class)));
            $events->addSubscriber(new PublishedIrcEventBridge($events));
            $events->addSubscriber(new NickProtectionSubscriber($protection, $vhost, $burst, $this->createStub(IrcPort\NetworkUserLookupPort::class), $this->createStub(IrcPort\ActiveProtocolModuleHolderInterface::class), $clock, $this->createStub(SyncNetworkIdentificationHandlerInterface::class)));
            $uid = new Uid('001ABC123');
            $nick = new Nick('Example');
            $name = new ChannelName('#test');
            $user = new NetworkUser($uid, $nick, new Ident('ident'), 'host.example', 'cloak.example', 'vhost.example', '+i', new DateTimeImmutable(), 'Real', '001', '*');
            $user->addChannel($name);
            $users->add($user);
            $channel = new Channel($name, '', new DateTimeImmutable());
            $channel->syncMember($uid, ChannelMemberRole::None);
            $channels->save($channel);
            $registry->markSyncCompleted($name->value);
            $identified->register($uid->value, 'Account');
            $languages->register($uid->value, 'es');
            $quitCount = 0;
            $events->addListener(UserLeftNetworkEvent::class, static function () use (&$quitCount): void { ++$quitCount; });
            $adapter = match ($protocol) {
                'standalone' => new UnrealStandaloneNetworkStateAdapter($events),
                'udb' => new UnrealUdbNetworkStateAdapter($events),
                default => new InspIRCdNetworkStateAdapter($events),
            };
            $before = hash('sha256', serialize([$users, $channels, $identified, $languages]));
            // Unknown targets exercise both resolver branches: a 9-character UID and a nickname.
            foreach ([['001ZZZZZZ'], ['Nobody'], []] as $params) {
                $adapter->handleMessage(new IRCMessage('KILL', '001', $params, 'reason'));
                self::assertSame($before, hash('sha256', serialize([$users, $channels, $identified, $languages])), $label);
            }
            $written = [];
            if ('service-direct' === $mode || 'service-operserv' === $mode) {
                $holder = $this->connectedHolder($written);
                $serviceActions = $this->serviceActions($protocol, $holder, new LocalUserQuitSync($events));
                if ('service-direct' === $mode) {
                    // A new killUser() caller gets the local cleanup without repeating it.
                    $serviceActions->killUser('001', $uid->value, 'reason');
                } else {
                    $module = $this->createStub(IrcPort\ProtocolModuleInterface::class);
                    $module->method('getServiceActions')->willReturn($serviceActions);
                    $connection = $this->createStub(IrcPort\ActiveProtocolModuleHolderInterface::class);
                    $connection->method('getProtocolModule')->willReturn($module);
                    $connection->method('getServerSid')->willReturn('001');
                    self::assertTrue(new ActiveConnectionKillNetworkUser($connection)->kill($uid->value, 'reason'), $label);
                }
                self::assertSame([':001 KILL 001ABC123 :reason'], $written, $label);
            } elseif ('quit' === $mode) {
                $adapter->handleMessage(new IRCMessage('QUIT', $uid->value, [], 'reason'));
            } else {
                $target = 'inbound-uid' === $mode ? $uid->value : $nick->value;
                $adapter->handleMessage(new IRCMessage('KILL', '001', [$target], 'reason'));
            }
            // No ghost user may remain before any later QUIT/KILL from the network.
            self::assertNull($users->findByUid($uid), $label);
            self::assertNull($channels->findByName($name), $label);
            self::assertNull($identified->findNick($uid->value), $label);
            $adapter->handleMessage(new IRCMessage('QUIT', $uid->value, [], 'reason'));
            $adapter->handleMessage(new IRCMessage('KILL', '001', ['001ZZZZZZ'], 'reason'));
            $adapter->handleMessage(new IRCMessage('KILL', '001', [$nick->value], 'reason'));
            self::assertNull($users->findByUid($uid), $label);
            self::assertNull($users->findByNick($nick), $label);
            self::assertNull($channel->getMember($uid), $label);
            self::assertNull($channels->findByName($name), $label);
            self::assertFalse($registry->isSyncCompleted($name->value), $label);
            self::assertNull($identified->findNick($uid->value), $label);
            self::assertNull($languages->find($uid->value), $label);
            self::assertSame(1, $quitCount, $label);
        }
    }

    /** @param list<string> $written */
    private function connectedHolder(array &$written): ActiveConnectionHolder
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });
        $holder = new ActiveConnectionHolder();
        $holder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));

        return $holder;
    }

    private function serviceActions(string $protocol, ActiveConnectionHolder $holder, LocalUserQuitSync $localUserQuitSync): IrcPort\ProtocolServiceActionsInterface
    {
        return match ($protocol) {
            'standalone' => new UnrealStandaloneProtocolServiceActions($holder, $localUserQuitSync),
            'udb' => new UnrealUdbProtocolServiceActions($holder, $this->createStub(UdbRecordWriterInterface::class), $this->createStub(UdbSessionStateInterface::class), $localUserQuitSync),
            default => new InspIRCdProtocolServiceActions($holder, $localUserQuitSync),
        };
    }
}
