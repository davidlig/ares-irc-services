<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\UserIdentityReceivedEvent;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(InspIRCdNetworkStateAdapter::class)]
#[CoversClass(NetworkEventEnricher::class)]
#[CoversClass(NetworkUser::class)]
#[CoversClass(UserIdentityReceivedEvent::class)]
final class ProtocolUserIdentityTest extends TestCase
{
    #[Test]
    public function partialIdentityUpdatesPreserveTheUserAndItsUnrelatedState(): void
    {
        [$adapter, $repository, $user] = $this->network();
        $time = $user->connectedAt;
        $adapter->handleMessage(IRCMessage::fromRawLine(':001AAAAAA FHOST display2.test *'));
        self::assertSame('display2.test', $user->getDisplayHost());
        self::assertSame('real.test', $user->hostname);
        $adapter->handleMessage(IRCMessage::fromRawLine(':001AAAAAA FHOST * real2.test'));
        self::assertSame('display2.test', $user->getDisplayHost());
        self::assertSame('real2.test', $user->hostname);
        $adapter->handleMessage(IRCMessage::fromRawLine(':001AAAAAA FIDENT displayed *'));
        self::assertSame('displayed', $user->ident->value);
        $adapter->handleMessage(IRCMessage::fromRawLine(':001AAAAAA FIDENT * realident'));
        self::assertSame('displayed', $user->ident->value);
        self::assertSame('realident', $user->realIdent->value);
        $adapter->handleMessage(IRCMessage::fromRawLine(':001AAAAAA FHOST * *'));
        $adapter->handleMessage(IRCMessage::fromRawLine(':001AAAAAA FIDENT * *'));
        self::assertSame('display2.test', $user->getDisplayHost());
        self::assertSame('real2.test', $user->hostname);
        self::assertSame('displayed', $user->ident->value);
        self::assertSame('realident', $user->realIdent->value);
        self::assertSame($user, $repository->findByUid($user->uid));
        self::assertSame(1, $repository->count());
        self::assertSame($time, $user->connectedAt);
        self::assertSame('fwAAAQ==', $user->ipBase64);
        self::assertSame('+ior', $user->getModes());
        self::assertTrue($user->isIdentified());
        self::assertSame(123, $user->serviceStamp);
        self::assertSame(['#Channel'], $user->getChannelNames());
    }

    #[Test]
    public function invalidOrUnresolvableUpdatesPreserveState(): void
    {
        [$adapter, , $user] = $this->network();
        foreach (['FHOST changed.test real2.test', 'FIDENT changed real2', ':001BBBBBB FHOST changed.test real2.test', ':001AAAAAA FHOST', ':001AAAAAA FIDENT', ':001AAAAAA FIDENT', ':001AAAAAA FIDENT * :'] as $line) {
            $adapter->handleMessage(IRCMessage::fromRawLine($line));
        }
        $adapter->handleMessage(new IRCMessage('FIDENT', '001AAAAAA', [''], null));
        self::assertSame('display.test', $user->getDisplayHost());
        self::assertSame('real.test', $user->hostname);
        self::assertSame('original', $user->ident->value);
    }

    /** @return array{InspIRCdNetworkStateAdapter, InMemoryNetworkUserRepository, NetworkUser} */
    private function network(): array
    {
        $users = new InMemoryNetworkUserRepository();
        $user = new NetworkUser(new Uid('001AAAAAA'), new Nick('Alice'), new Ident('original'), 'real.test', 'cloak.test', 'display.test', '+ior', new DateTimeImmutable('@100'), 'Alice', '001', 'fwAAAQ==', 123);
        $user->addChannel(new ChannelName('#Channel'));
        $users->add($user);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new NetworkEventEnricher($this->createStub(ChannelRepositoryInterface::class), $users, $dispatcher, $this->createStub(SkipIdentifiedModeStripRegistry::class), $this->createStub(ActiveChannelModeSupportProviderInterface::class), $this->createStub(ActiveProtocolModuleHolderInterface::class)));

        return [new InspIRCdNetworkStateAdapter($dispatcher), $users, $user];
    }
}
