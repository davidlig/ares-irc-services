<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelModeParameterMerge;
use App\Irc\Adapter\Network\ChannelModeStateSynchronizer;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\ChannelName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(NetworkEventEnricher::class)]
#[CoversClass(InspIRCdNetworkStateAdapter::class)]
#[CoversClass(ChannelModeStateSynchronizer::class)]
#[CoversClass(ChannelModeParameterMerge::class)]
final class InspIRCdTimestampRegressionTest extends TestCase
{
    /** @return array{Channel, InspIRCdNetworkStateAdapter} */
    private function network(): array
    {
        $channel = new Channel(new ChannelName('#test'), '+nk', new DateTimeImmutable('@100'));
        $channel->applyModeParam('k', 'akey');
        $repo = new InMemoryChannelRepository();
        $repo->save($channel);
        $dispatcher = new EventDispatcher();
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn(['k']);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);
        $dispatcher->addSubscriber(new NetworkEventEnricher($repo, new InMemoryNetworkUserRepository(), $dispatcher, $this->createStub(SkipIdentifiedModeStripRegistry::class), $provider, $this->createStub(ActiveProtocolModuleHolderInterface::class)));

        return [$channel, new InspIRCdNetworkStateAdapter($dispatcher)];
    }

    public function testLowerFmodeIsAcceptedWithoutLoweringChannelTimestamp(): void
    {
        [$c,$a] = $this->network();
        $a->handleMessage(IRCMessage::fromRawLine(':001 FMODE #test 99 +t'));
        self::assertSame('+nkt', $c->getModes());
        self::assertSame(100, $c->getCreatedAt()->getTimestamp());
    }

    public function testLowerLmodeIsAcceptedWithoutLoweringChannelTimestamp(): void
    {
        [$c,$a] = $this->network();
        $a->handleMessage(IRCMessage::fromRawLine(':001 LMODE #test 99 b bad!*@* 90 setter'));
        self::assertSame(['bad!*@*'], $c->getBans());
        self::assertSame(100, $c->getCreatedAt()->getTimestamp());
    }

    public function testEqualFjoinKeyUsesInspLexicallySmallerValue(): void
    {
        [$c,$a] = $this->network();
        $a->handleMessage(IRCMessage::fromRawLine(':001 FJOIN #test 100 +nk zkey :'));
        self::assertSame('akey', $c->getModeParam('k'));
    }

    public function testHigherModeAndListMessagesPreserveExactStateHash(): void
    {
        [$c,$a] = $this->network();
        $before = hash('sha256', serialize($c));
        $a->handleMessage(IRCMessage::fromRawLine(':001 FMODE #test 101 +t'));
        $a->handleMessage(IRCMessage::fromRawLine(':001 LMODE #test 101 b bad!*@* 90 setter'));
        self::assertSame($before, hash('sha256',serialize($c)));
    }
}
