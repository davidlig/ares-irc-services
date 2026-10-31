<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelModeParameterMerge;
use App\Irc\Adapter\Network\ChannelModeStateSynchronizer;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelModeStateSynchronizer::class)]
final class ChannelModeStateSynchronizerTest extends TestCase
{
    private ChannelModeStateSynchronizer $synchronizer;

    #[Test]
    public function applyOutgoingAddsAndRemovesBanListMode(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '+b', ['*!*@banned.test'], $support);

        self::assertSame('+nt', $channel->getModes());
        self::assertSame(['*!*@banned.test'], $channel->getBans());

        $this->synchronizer->applyOutgoing($channel, '-b', ['*!*@banned.test'], $support);

        self::assertSame('+nt', $channel->getModes());
        self::assertSame([], $channel->getBans());
    }

    #[Test]
    public function applyOutgoingAddsAndRemovesExemptListMode(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '+e', ['*!*@exempt.test'], $support);

        self::assertSame('+nt', $channel->getModes());
        self::assertSame(['*!*@exempt.test'], $channel->getExempts());

        $this->synchronizer->applyOutgoing($channel, '-e', ['*!*@exempt.test'], $support);

        self::assertSame('+nt', $channel->getModes());
        self::assertSame([], $channel->getExempts());
    }

    #[Test]
    public function applyOutgoingAddsAndRemovesInviteExceptionListMode(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '+I', ['*!*@invex.test'], $support);

        self::assertSame('+nt', $channel->getModes());
        self::assertSame(['*!*@invex.test'], $channel->getInviteExceptions());

        $this->synchronizer->applyOutgoing($channel, '-I', ['*!*@invex.test'], $support);

        self::assertSame('+nt', $channel->getModes());
        self::assertSame([], $channel->getInviteExceptions());
    }

    #[Test]
    public function applyOutgoingHandlesMultipleListModesInSingleString(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing(
            $channel,
            '+beI',
            ['*!*@ban1.test', '*!*@exempt1.test', '*!*@invex1.test'],
            $support,
        );

        self::assertSame('+nt', $channel->getModes());
        self::assertSame(['*!*@ban1.test'], $channel->getBans());
        self::assertSame(['*!*@exempt1.test'], $channel->getExempts());
        self::assertSame(['*!*@invex1.test'], $channel->getInviteExceptions());

        $this->synchronizer->applyOutgoing(
            $channel,
            '-beI',
            ['*!*@ban1.test', '*!*@exempt1.test', '*!*@invex1.test'],
            $support,
        );

        self::assertSame('+nt', $channel->getModes());
        self::assertSame([], $channel->getBans());
        self::assertSame([], $channel->getExempts());
        self::assertSame([], $channel->getInviteExceptions());
    }

    #[Test]
    public function applyOutgoingListModesWithoutParametersDoesNotFail(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '+b', [], $support);

        self::assertSame('+nt', $channel->getModes());
        self::assertSame([], $channel->getBans());
    }

    #[Test]
    public function applyOutgoingSkipsPrefixModesAndAdvancesParamIndex(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing(
            $channel,
            '+ob',
            ['001ABC', '*!*@ban.test'],
            $support,
        );

        self::assertSame('+nt', $channel->getModes());
        self::assertSame(['*!*@ban.test'], $channel->getBans());
    }

    #[Test]
    public function applyOutgoingPrefixModeWithoutParamDoesNotFail(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '+o', [], $support);

        self::assertSame('+nt', $channel->getModes());
    }

    #[Test]
    public function applyOutgoingUpdatesNormalModesAndModeParams(): void
    {
        $channel = $this->createChannel('+n');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '+tk', ['secret'], $support);

        self::assertStringContainsString('n', $channel->getModes());
        self::assertStringContainsString('t', $channel->getModes());
        self::assertStringContainsString('k', $channel->getModes());
        self::assertSame('secret', $channel->getModeParam('k'));

        $this->synchronizer->applyOutgoing($channel, '-k', ['secret'], $support);

        self::assertNull($channel->getModeParam('k'));
        self::assertStringNotContainsString('k', $channel->getModes());
    }

    #[Test]
    public function applyOutgoingUnsetWithParamWithoutParamsClearsParam(): void
    {
        $channel = $this->createChannel('+nk');
        $channel->applyModeParam('k', 'secret');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '-k', [], $support);

        self::assertNull($channel->getModeParam('k'));
    }

    #[Test]
    public function applyOutgoingDoesNotDuplicateDeltaWhenAlreadySet(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '+n', [], $support);

        self::assertSame('+nt', $channel->getModes());
    }

    #[Test]
    public function applyOutgoingClearingAllModesLeavesEmptyModesString(): void
    {
        $channel = $this->createChannel('+n');
        $support = $this->createModeSupport();

        $this->synchronizer->applyOutgoing($channel, '-n', [], $support);

        self::assertSame('', $channel->getModes());
    }

    #[Test]
    public function applyInitialParamsEarlyReturnsOnEmptyParams(): void
    {
        $channel = $this->createChannel('+k');
        $support = $this->createModeSupport();

        $this->synchronizer->applyInitialParams($channel, '+k', [], $support);

        self::assertNull($channel->getModeParam('k'));
    }

    #[Test]
    public function applyInitialParamsAppliesParam(): void
    {
        $channel = $this->createChannel('+k');
        $support = $this->createModeSupport();

        $this->synchronizer->applyInitialParams($channel, '+k', ['key123'], $support);

        self::assertSame('key123', $channel->getModeParam('k'));
    }

    #[Test]
    public function applyInitialParamsWithMergeRulesPrefersExpectedParam(): void
    {
        $channel = $this->createChannel('+l');
        $channel->applyModeParam('l', '50');
        $support = $this->createModeSupport();

        $this->synchronizer->applyInitialParams(
            $channel,
            '+l',
            ['100'],
            $support,
            ['l' => ChannelModeParameterMerge::NumericMaximum],
        );

        self::assertSame('100', $channel->getModeParam('l'));
    }

    #[Test]
    public function mergeEqualTimestampModesMergesDistinctModes(): void
    {
        $merged = $this->synchronizer->mergeEqualTimestampModes('+nt', '+tk');

        self::assertStringContainsString('n', $merged);
        self::assertStringContainsString('t', $merged);
        self::assertStringContainsString('k', $merged);
        self::assertStringStartsWith('+', $merged);
    }

    #[Test]
    public function mergeEqualTimestampModesReturnsEmptyStringWhenNoModes(): void
    {
        self::assertSame('', $this->synchronizer->mergeEqualTimestampModes('', ''));
    }

    #[Test]
    public function applyReceivedAppliesRoleMemberPrefixChanges(): void
    {
        $channel = $this->createChannel('+nt');
        $user = new NetworkUser(
            new Uid('001AAA'),
            new Nick('Alice'),
            new Ident('alice'),
            'host.test',
            'cloak.test',
            'host.test',
            '+r',
            new DateTimeImmutable('@1000'),
            'Alice Test',
            '001',
            '192.0.2.1',
        );
        $channel->syncMember(new Uid('001AAA'), ChannelMemberRole::None, []);

        $support = $this->createModeSupport();

        $this->synchronizer->applyReceived(
            $channel,
            '+o',
            ['Alice'],
            $support,
            static fn (string $nick): ?NetworkUser => 'Alice' === $nick ? $user : null,
        );

        $member = $channel->getMember(new Uid('001AAA'));
        self::assertNotNull($member);
        self::assertSame(['o'], $member->prefixLetters);

        $this->synchronizer->applyReceived(
            $channel,
            '-o',
            ['Alice'],
            $support,
            static fn (string $nick): ?NetworkUser => 'Alice' === $nick ? $user : null,
        );

        $memberAfter = $channel->getMember(new Uid('001AAA'));
        self::assertNotNull($memberAfter);
        self::assertSame([], $memberAfter->prefixLetters);
    }

    #[Test]
    public function applyReceivedAppliesListModes(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        $this->synchronizer->applyReceived(
            $channel,
            '+b',
            ['*!*@banned.test'],
            $support,
            static fn (string $param): ?NetworkUser => null,
        );

        self::assertSame(['*!*@banned.test'], $channel->getBans());
    }

    #[Test]
    public function applyListSnapshotAppliesBans(): void
    {
        $channel = $this->createChannel('+nt');

        $this->synchronizer->applyListSnapshot(
            $channel,
            'b',
            ['*!*@ban1.test', 'setter1', '1000', '*!*@ban2.test', 'setter2', '1001'],
        );

        self::assertSame(['*!*@ban1.test', '*!*@ban2.test'], $channel->getBans());
    }

    #[Test]
    public function applyInitialParamsHandlesMinusAndNonParamModesAndExhaustedParams(): void
    {
        $channel = $this->createChannel('+k');
        $channel->applyModeParam('k', 'existing');
        $support = $this->createModeSupport();

        // '-k' is ignored for initial params, '+n' has no param on set, '+k+l' with 1 param breaks on +l
        $this->synchronizer->applyInitialParams(
            $channel,
            '+n-k+k+l',
            ['incomingKey'],
            $support,
        );

        self::assertSame('incomingKey', $channel->getModeParam('k'));
        self::assertNull($channel->getModeParam('l'));
    }

    #[Test]
    public function applyInitialParamsWithMergeRulesRejectsWhenPrefersIncomingIsFalse(): void
    {
        $channel = $this->createChannel('+l');
        $channel->applyModeParam('l', '100');
        $support = $this->createModeSupport();

        $this->synchronizer->applyInitialParams(
            $channel,
            '+l',
            ['50'],
            $support,
            ['l' => ChannelModeParameterMerge::NumericMaximum],
        );

        self::assertSame('100', $channel->getModeParam('l'));
    }

    #[Test]
    public function applyReceivedHandlesUnknownUserAndUnsetWithParam(): void
    {
        $channel = $this->createChannel('+nk');
        $channel->applyModeParam('k', 'secret');
        $support = $this->createModeSupport();

        $this->synchronizer->applyReceived(
            $channel,
            '+o-k',
            ['UnknownNick', 'secret'],
            $support,
            static fn (string $nick): ?NetworkUser => null,
        );

        self::assertNull($channel->getModeParam('k'));
    }

    #[Test]
    public function applyListSnapshotAppliesExemptAndInviteExceptionsAndStopsOnEmpty(): void
    {
        $channel = $this->createChannel('+nt');

        $this->synchronizer->applyListSnapshot(
            $channel,
            'e',
            ['*!*@exempt.test', 'setter', '1000', '', 'setter2', '1001'],
        );
        $this->synchronizer->applyListSnapshot(
            $channel,
            'I',
            ['*!*@invex.test', 'setter', '1000'],
        );

        self::assertSame(['*!*@exempt.test'], $channel->getExempts());
        self::assertSame(['*!*@invex.test'], $channel->getInviteExceptions());
    }

    #[Test]
    public function applyOutgoingIgnoresUnsupportedListModeLetter(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getListModeLetters')->willReturn(['g']);

        $this->synchronizer->applyOutgoing($channel, '+g', ['mask'], $support);

        self::assertSame('+nt', $channel->getModes());
    }

    #[Test]
    public function applyReceivedHandlesListModesWithoutParamsAndRemovals(): void
    {
        $channel = $this->createChannel('+nt');
        $support = $this->createModeSupport();

        // Without params
        $this->synchronizer->applyReceived(
            $channel,
            '+b+o+k-k',
            [],
            $support,
            static fn (string $nick): ?NetworkUser => null,
        );
        self::assertSame([], $channel->getBans());

        // With params +e, -e, +I, -I
        $this->synchronizer->applyReceived(
            $channel,
            '+eI',
            ['*!*@exempt.test', '*!*@invex.test'],
            $support,
            static fn (string $nick): ?NetworkUser => null,
        );
        self::assertSame(['*!*@exempt.test'], $channel->getExempts());
        self::assertSame(['*!*@invex.test'], $channel->getInviteExceptions());

        $this->synchronizer->applyReceived(
            $channel,
            '-eI',
            ['*!*@exempt.test', '*!*@invex.test'],
            $support,
            static fn (string $nick): ?NetworkUser => null,
        );
        self::assertSame([], $channel->getExempts());
        self::assertSame([], $channel->getInviteExceptions());
    }

    protected function setUp(): void
    {
        $this->synchronizer = new ChannelModeStateSynchronizer();
    }

    private function createChannel(string $modes = ''): Channel
    {
        return new Channel(new ChannelName('#test'), $modes, new DateTimeImmutable('@1000'));
    }

    private function createModeSupport(): ChannelModeSupportInterface
    {
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn(['k']);

        return $support;
    }
}
