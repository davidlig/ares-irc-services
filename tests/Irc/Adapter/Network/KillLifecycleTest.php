<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\LocalUserQuitSync;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
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
        foreach (['inspircd', 'standalone', 'udb', 'service', 'quit'] as $source) {
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
            $events->addSubscriber(new NetworkStateSubscriber($users, $channels));
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
            $identified->register($uid->value, 'Account');
            $languages->register($uid->value, 'es');
            $quitCount = 0;
            $events->addListener(UserLeftNetworkEvent::class, static function () use (&$quitCount): void { ++$quitCount; });
            $adapter = match ($source) {
                'standalone' => new UnrealStandaloneNetworkStateAdapter($events),
                'udb' => new UnrealUdbNetworkStateAdapter($events),
                default => new InspIRCdNetworkStateAdapter($events),
            };
            $before = hash('sha256', serialize([$users, $channels, $identified, $languages]));
            $adapter->handleMessage(new IRCMessage('KILL', '001', ['001UNKNOWN'], 'reason'));
            self::assertSame($before, hash('sha256', serialize([$users, $channels, $identified, $languages])), $source);
            $adapter->handleMessage(new IRCMessage('KILL', '001', [], 'reason'));
            self::assertSame($before, hash('sha256', serialize([$users, $channels, $identified, $languages])), $source);
            if ('service' === $source) {
                $wire = $this->createMock(IrcPort\ProtocolServiceActionsInterface::class);
                $wire->expects(self::once())->method('killUser')->with('001', $uid->value, 'reason');
                $module = $this->createStub(IrcPort\ProtocolModuleInterface::class);
                $module->method('getServiceActions')->willReturn($wire);
                $connection = $this->createStub(IrcPort\ActiveProtocolModuleHolderInterface::class);
                $connection->method('getProtocolModule')->willReturn($module);
                $connection->method('getServerSid')->willReturn('001');
                self::assertTrue(new ActiveConnectionKillNetworkUser($connection, new LocalUserQuitSync($events))->kill($uid->value, 'reason'));
            } elseif ('quit' === $source) {
                $adapter->handleMessage(new IRCMessage('QUIT', $uid->value, [], 'reason'));
            } else {
                $adapter->handleMessage(new IRCMessage('KILL', '001', [$uid->value], 'reason'));
            }
            $adapter->handleMessage(new IRCMessage('QUIT', $uid->value, [], 'reason'));
            $adapter->handleMessage(new IRCMessage('KILL', '001', ['001UNKNOWN'], 'reason'));
            self::assertNull($users->findByUid($uid), $source);
            self::assertNull($users->findByNick($nick), $source);
            self::assertNull($channel->getMember($uid), $source);
            self::assertNull($identified->findNick($uid->value), $source);
            self::assertNull($languages->find($uid->value), $source);
            self::assertSame(1, $quitCount, $source);
        }
    }
}
