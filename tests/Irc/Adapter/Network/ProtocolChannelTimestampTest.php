<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelModeParameterMerge;
use App\Irc\Adapter\Network\ChannelModeStateSynchronizer;
use App\Irc\Adapter\Network\Event\ChannelJoinReceivedEvent;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(NetworkEventEnricher::class)]
#[CoversClass(ChannelModeStateSynchronizer::class)]
#[CoversClass(ChannelModeParameterMerge::class)]
#[CoversClass(Channel::class)]
final class ProtocolChannelTimestampTest extends TestCase
{
    #[Test]
    public function lowerTimestampReplacesGenerationStateButKeepsMembers(): void
    {
        $channel = new Channel(new ChannelName('#channel'), '+ntk', new DateTimeImmutable('@200'));
        $oldUid = new Uid('old');
        $channel->syncMember($oldUid, ChannelMemberRole::Op);
        $channel->applyModeParam('k', 'obsolete');
        $channel->addBan('obsolete!*@*');
        $this->enrich($channel, new ChannelJoinReceivedEvent(
            new ChannelName('#channel'),
            100,
            '+l',
            [['uid' => new Uid('new'), 'role' => ChannelMemberRole::None, 'prefixLetters' => []]],
            ['b' => ['current!*@*']],
            ['25'],
        ));

        self::assertSame(100, $channel->getCreatedAt()->getTimestamp());
        self::assertSame('+l', $channel->getModes());
        self::assertSame(['l' => '25'], $channel->getModeParams());
        self::assertSame(['current!*@*'], $channel->getBans());
        self::assertSame([], $channel->getMember($oldUid)?->prefixLetters);
        self::assertTrue($channel->isMember(new Uid('new')));
    }

    #[Test]
    public function higherTimestampCannotReplaceExistingGenerationState(): void
    {
        $channel = new Channel(new ChannelName('#channel'), '+ntk', new DateTimeImmutable('@100'));
        $channel->syncMember(new Uid('existing'), ChannelMemberRole::Op);
        $channel->applyModeParam('k', 'winner');
        $channel->addBan('winner!*@*');
        $this->enrich($channel, new ChannelJoinReceivedEvent(
            new ChannelName('#channel'),
            200,
            '+l',
            [['uid' => new Uid('new'), 'role' => ChannelMemberRole::Op, 'prefixLetters' => ['o']]],
            ['b' => ['stale!*@*']],
            ['5'],
        ));

        self::assertSame(100, $channel->getCreatedAt()->getTimestamp());
        self::assertSame('+ntk', $channel->getModes());
        self::assertSame(['k' => 'winner'], $channel->getModeParams());
        self::assertSame(['winner!*@*'], $channel->getBans());
        self::assertSame(['o'], $channel->getMember(new Uid('existing'))?->prefixLetters);
        self::assertSame([], $channel->getMember(new Uid('new'))?->prefixLetters);
    }

    #[Test]
    public function equalTimestampMergesGenerationStateAndPrefixLetters(): void
    {
        $channel = new Channel(new ChannelName('#channel'), '+nt', new DateTimeImmutable('@100'));
        $existingUid = new Uid('existing');
        $channel->syncMember($existingUid, ChannelMemberRole::Op, ['o']);
        $channel->applyModeParam('l', '10');
        $channel->addBan('existing!*@*');
        $this->enrich($channel, new ChannelJoinReceivedEvent(
            new ChannelName('#channel'),
            100,
            '+l',
            [
                ['uid' => $existingUid, 'role' => ChannelMemberRole::Voice, 'prefixLetters' => ['v']],
                ['uid' => new Uid('new'), 'role' => ChannelMemberRole::None, 'prefixLetters' => []],
            ],
            ['b' => ['incoming!*@*']],
            ['20'],
        ));

        self::assertSame(100, $channel->getCreatedAt()->getTimestamp());
        self::assertSame('+ntl', $channel->getModes());
        self::assertSame('20', $channel->getModeParam('l'));
        self::assertSame(['existing!*@*', 'incoming!*@*'], $channel->getBans());
        self::assertSame(['o', 'v'], $channel->getMember($existingUid)?->prefixLetters);
        self::assertSame([], $channel->getMember(new Uid('new'))?->prefixLetters);
    }

    #[Test]
    public function nativeJoinSnapshotsFromEveryAdapterUseTheSharedTimestampProjection(): void
    {
        foreach (['inspircd', 'unreal', 'unrealudb'] as $protocol) {
            foreach (['lower', 'equal', 'higher'] as $relation) {
                $initialTimestamp = 'lower' === $relation ? 200 : ('higher' === $relation ? 50 : 100);
                $incomingTimestamp = 'higher' === $relation ? 100 : 100;
                $channel = new Channel(new ChannelName('#channel'), '+ntlk', new DateTimeImmutable('@' . $initialTimestamp));
                $existingUid = new Uid('existing');
                $channel->syncMember($existingUid, ChannelMemberRole::Op, ['o']);
                $channel->applyModeParam('l', '10');
                $channel->applyModeParam('k', 'akey');
                $channel->addBan('existing!*@*');
                $this->enrichFromAdapter($channel, $protocol, $incomingTimestamp, 'equal' === $relation);

                $winnerTimestamp = 'lower' === $relation ? 100 : $initialTimestamp;
                self::assertSame($winnerTimestamp, $channel->getCreatedAt()->getTimestamp());
                self::assertSame('lower' === $relation ? '+nlk' : '+ntlk', $channel->getModes());
                self::assertSame('higher' === $relation ? '10' : '20', $channel->getModeParam('l'));
                self::assertSame('higher' === $relation || ('inspircd' === $protocol && 'equal' === $relation) ? 'akey' : 'zkey', $channel->getModeParam('k'));
                $expectedExistingPrefixes = match ($relation) {
                    'lower' => ['v'],
                    'equal' => ['o', 'v'],
                    'higher' => ['o'],
                };
                self::assertSame($expectedExistingPrefixes, $channel->getMember($existingUid)?->prefixLetters, $protocol . ' ' . $relation);
                self::assertSame([], $channel->getMember(new Uid('new'))?->prefixLetters);
                if ('inspircd' !== $protocol) {
                    self::assertSame(match ($relation) {
                        'lower' => ['incoming!*@*'],
                        'equal' => ['existing!*@*', 'incoming!*@*'],
                        'higher' => ['existing!*@*'],
                    }, $channel->getBans());
                }
            }
        }
    }

    private function enrich(Channel $channel, ChannelJoinReceivedEvent $event): void
    {
        $channels = $this->createMock(ChannelRepositoryInterface::class);
        $channels->method('findByName')->willReturn($channel);
        $channels->expects(self::once())->method('save')->with($channel);
        $dispatcher = new EventDispatcher();
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn(['k']);
        $enricher = new NetworkEventEnricher(
            $channels,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $dispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistry::class),
            new class($support) implements ActiveChannelModeSupportProviderInterface {
                public function __construct(private readonly ChannelModeSupportInterface $support) {}

                public function getSupport(): ChannelModeSupportInterface
                {
                    return $this->support;
                }
            },
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
        );
        $dispatcher->addSubscriber($enricher);
        $dispatcher->dispatch($event);
    }

    /** @param 'inspircd'|'unreal'|'unrealudb' $protocol */
    private function enrichFromAdapter(Channel $channel, string $protocol, int $timestamp, bool $equalTimestamp): void
    {
        $channels = $this->createMock(ChannelRepositoryInterface::class);
        $channels->method('findByName')->willReturn($channel);
        $channels->expects(self::once())->method('save')->with($channel);
        $dispatcher = new EventDispatcher();
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn(['k']);
        $enricher = new NetworkEventEnricher(
            $channels,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $dispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistry::class),
            new class($support) implements ActiveChannelModeSupportProviderInterface {
                public function __construct(private readonly ChannelModeSupportInterface $support) {}

                public function getSupport(): ChannelModeSupportInterface
                {
                    return $this->support;
                }
            },
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
        );
        $dispatcher->addSubscriber($enricher);
        $adapter = match ($protocol) {
            'inspircd' => new InspIRCdNetworkStateAdapter($dispatcher),
            'unreal' => new UnrealStandaloneNetworkStateAdapter($dispatcher),
            'unrealudb' => new UnrealUdbNetworkStateAdapter($dispatcher),
        };
        $existingMemberEntry = 'inspircd' === $protocol
            ? ($equalTimestamp ? 'ov,existing:1' : 'v,existing:1')
            : ($equalTimestamp ? '@+existing' : '+existing');
        $message = 'inspircd' === $protocol
            ? new IRCMessage('FJOIN', null, ['#channel', (string) $timestamp, '+nlk', '20', 'zkey'], $existingMemberEntry . ' ,new:1')
            : new IRCMessage('SJOIN', null, [(string) $timestamp, '#channel', '+nlk', '20', 'zkey'], $existingMemberEntry . ' new &incoming!*@*');

        $adapter->handleMessage($message);
        if ('inspircd' === $protocol) {
            $adapter->handleMessage(new IRCMessage('FMODE', null, ['#channel', '200', '+k', 'stale-key']));
            $adapter->handleMessage(new IRCMessage('LMODE', null, ['#channel', '200', 'b', 'stale!*@*']));
        }
    }
}
