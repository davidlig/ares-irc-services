<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\In\Event\ChannelRankEventBridge;
use App\Irc\Adapter\Network\Event\ChannelModeReceivedEvent;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\ChannelMemberRankGrantedEvent;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ChannelRankEventBridge::class)]
#[CoversClass(ChannelMemberRankGrantedEvent::class)]
final class ChannelRankEventBridgeTest extends TestCase
{
    #[Test]
    public function itSubscribesBeforeChanservEnforcement(): void
    {
        self::assertSame(
            [ChannelModeReceivedEvent::class => ['publishGrantedRanks', 255]],
            ChannelRankEventBridge::getSubscribedEvents(),
        );
    }

    #[Test]
    public function itConsumesEveryConcreteParameterAndPublishesCanonicalSemanticRanks(): void
    {
        $alice = new SenderView('001AAAA', 'Alice', 'a', 'host', 'cloak', 'ip');
        $bob = new SenderView('001BBBB', 'Bob', 'b', 'host', 'cloak', 'ip');
        $users = $this->createMock(NetworkUserLookupPort::class);
        $users->expects(self::exactly(2))->method('findByUid')->willReturnCallback(
            static fn (string $value): ?SenderView => '001AAAA' === $value ? $alice : null,
        );
        $users->expects(self::once())->method('findByNick')->with('Bob')->willReturn($bob);
        $published = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$published): object {
                $published[] = $event;

                return $event;
            },
        );
        $bridge = new ChannelRankEventBridge($this->provider($this->support()), $users, $dispatcher);

        $bridge->publishGrantedRanks(new ChannelModeReceivedEvent(
            new ChannelName('#Modes'),
            '+bko-vL+a',
            ['*!*@bad.example', 'secret', '001AAAA', 'RemovedNick', '#elsewhere', 'Bob'],
        ));

        self::assertCount(2, $published);
        self::assertInstanceOf(ChannelMemberRankGrantedEvent::class, $published[0]);
        self::assertSame(['#Modes', '001AAAA', 'op'], [
            $published[0]->channelName,
            $published[0]->uid,
            $published[0]->rank,
        ]);
        self::assertInstanceOf(ChannelMemberRankGrantedEvent::class, $published[1]);
        self::assertSame(['#Modes', '001BBBB', 'admin'], [
            $published[1]->channelName,
            $published[1]->uid,
            $published[1]->rank,
        ]);
    }

    #[Test]
    public function itIgnoresUnknownRanksMissingParametersAndUnresolvedMembers(): void
    {
        $support = $this->support(prefixes: ['y', 'o', 'v'], listModes: [], setWithParameter: [], unsetWithParameter: []);
        $users = $this->createMock(NetworkUserLookupPort::class);
        $users->expects(self::once())->method('findByUid')->with('Unknown')->willReturn(null);
        $users->expects(self::once())->method('findByNick')->with('Unknown')->willReturn(null);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');
        $bridge = new ChannelRankEventBridge($this->provider($support), $users, $dispatcher);

        $bridge->publishGrantedRanks(new ChannelModeReceivedEvent(
            new ChannelName('#Modes'),
            '+xyov',
            ['ignored-rank', 'Unknown'],
        ));
    }

    private function provider(ChannelModeSupportInterface $support): ActiveChannelModeSupportProviderInterface
    {
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        return $provider;
    }

    /**
     * @param list<string> $prefixes
     * @param list<string> $listModes
     * @param list<string> $setWithParameter
     * @param list<string> $unsetWithParameter
     */
    private function support(
        array $prefixes = ['q', 'a', 'o', 'h', 'v'],
        array $listModes = ['b', 'e', 'I'],
        array $setWithParameter = ['k', 'L', 'l'],
        array $unsetWithParameter = ['k', 'L'],
    ): ChannelModeSupportInterface {
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn($prefixes);
        $support->method('getListModeLetters')->willReturn($listModes);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn($setWithParameter);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn($unsetWithParameter);

        return $support;
    }
}
