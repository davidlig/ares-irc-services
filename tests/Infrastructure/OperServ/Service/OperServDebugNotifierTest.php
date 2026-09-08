<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Service;

use App\Application\Port\ChannelServiceActionsPort;
use App\Infrastructure\OperServ\Service\OperServDebugNotifier;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OperServDebugNotifier::class)]
final class OperServDebugNotifierTest extends TestCase
{
    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsNull(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: null);

        self::assertFalse($debug->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsEmpty(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: '');

        self::assertFalse($debug->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsTrueWhenChannelIsSet(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: '#ircops');

        self::assertTrue($debug->isConfigured());
    }

    #[Test]
    public function ensureChannelJoinedCallsChannelActionsWhenConfigured(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService')->with('#ircops');

        $debug = $this->createDebugNotifier(
            channelActions: $channelActions,
            debugChannel: '#ircops',
        );

        $debug->ensureChannelJoined();
    }

    #[Test]
    public function ensureChannelJoinedDoesNothingWhenNotConfigured(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('joinChannelAsService');

        $debug = $this->createDebugNotifier(
            channelActions: $channelActions,
            debugChannel: null,
        );

        $debug->ensureChannelJoined();
    }

    #[Test]
    public function notifySendsRawMessageWhenConfigured(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')
            ->with('#ircops', 'raw debug message', 'NOTICE');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService')->with('#ircops');

        $debug = $this->createDebugNotifier(
            channelActions: $channelActions,
            notifier: $notifier,
            debugChannel: '#ircops',
        );

        $debug->notify('raw debug message');
    }

    #[Test]
    public function notifyDoesNothingWhenNotConfigured(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            debugChannel: null,
        );

        $debug->notify('raw debug message');
    }

    #[Test]
    public function logSendsToChannelWhenConfigured(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'KILL', 'BadUser', 'user@host', '10.0.0.1', 'Flooding');
    }

    #[Test]
    public function logSendsMessageWithoutReasonWhenReasonIsNull(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->willReturnCallback(static fn (string $id, array $params) => $id);

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'KILL', 'BadUser', 'user@host', '10.0.0.1', null);
    }

    #[Test]
    public function logIncludesExtraInContext(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            debugChannel: null,
        );

        $debug->log('Admin', 'KILL', 'BadUser', null, null, null, ['key' => 'value']);
    }

    #[Test]
    public function logForGlobalCommandUsesSpecialFormat(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('debug.action_global', self::callback(static fn (array $params) => isset($params['%type%'])
                && 'PRIVMSG' === $params['%type%']
                && '4' === $params['%count%']
                && 'test message' === $params['%message%']));

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'GLOBAL', 'ChanServ', null, null, 'test message', ['type' => 'PRIVMSG', 'count' => '4']);
    }

    #[Test]
    public function isIrcopOrRootReturnsTrueForRoot(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('RootUser', 42, true, false)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity));
        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
            authorization: $authorization,
        );

        self::assertTrue($debug->isIrcopOrRoot('RootUser', true, false));
    }

    #[Test]
    public function isIrcopOrRootReturnsTrueForIdentifiedIrcop(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('OperUser', 42, true, true)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus));

        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
            authorization: $authorization,
        );

        self::assertTrue($debug->isIrcopOrRoot('OperUser', true, true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForNonOper(): void
    {
        $debug = $this->createDebugNotifier();

        self::assertFalse($debug->isIrcopOrRoot('NormalUser', false, false));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForOperNotIdentified(): void
    {
        $debug = $this->createDebugNotifier();

        self::assertFalse($debug->isIrcopOrRoot('SomeUser', false, true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForIdentifiedNonIrcop(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('NormalUser', 42, true, true)))
            ->willReturn(AuthorizationDecision::denied());

        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
            authorization: $authorization,
        );

        self::assertFalse($debug->isIrcopOrRoot('NormalUser', true, true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForIdentifiedUserWithoutRegisteredNick(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(null);

        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
        );

        self::assertFalse($debug->isIrcopOrRoot('UnknownUser', true, true));
    }

    #[Test]
    public function getDebugChannelReturnsConfiguredChannel(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: '#ircops');

        self::assertSame('#ircops', $debug->getDebugChannel());
    }

    #[Test]
    public function getDebugChannelReturnsNullWhenNotConfigured(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: null);

        self::assertNull($debug->getDebugChannel());
    }

    #[Test]
    public function logWithDurationInMessage(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')
            ->willReturnCallback(static function (string $key, array $params = []): string {
                if ('debug.actionWithDuration' === $key) {
                    self::assertArrayHasKey('%duration%', $params);
                    self::assertIsString($params['%duration%']);

                    return 'GLINE added with duration ' . $params['%duration%'];
                }

                return $key;
            });

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'GLINE', 'user@host', null, null, 'Spam', ['duration' => '1d']);
    }

    #[Test]
    public function logWithEmptyReasonDoesNotFormat(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')
            ->willReturnCallback(static function (string $key, array $params = []): string {
                // When reason is null/empty, we don't use prefix translation
                return 'Debug message';
            });

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        // Just test that it works with empty reason
        $debug->log('Admin', 'KILL', 'BadUser', 'user@host', '127.0.0.1', null, []);
    }

    #[Test]
    public function getServiceNameReturnsOperserv(): void
    {
        $debug = $this->createDebugNotifier();

        self::assertSame('operserv', $debug->getServiceName());
    }

    #[Test]
    public function getUserLookupReturnsConfiguredLookup(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $debug = $this->createDebugNotifier(userLookup: $userLookup);

        self::assertSame($userLookup, $debug->getUserLookup());
    }

    #[Test]
    public function getIdentifiedRegistryReturnsConfiguredRegistry(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $debug = $this->createDebugNotifier(identifiedRegistry: $identifiedRegistry);

        self::assertSame($identifiedRegistry, $debug->getIdentifiedRegistry());
    }

    private function createDebugNotifier(
        ?ChannelServiceActionsPort $channelActions = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?OperServNotifierInterface $notifier = null,
        ?IdentifiedSessionRegistry $identifiedRegistry = null,
        ?NickAccountQuery $nickAccounts = null,
        ?OperatorAuthorizationQuery $authorization = null,
        ?TranslatorInterface $translator = null,
        ?string $debugChannel = '#ircops',
    ): OperServDebugNotifier {
        return new OperServDebugNotifier(
            channelActions: $channelActions ?? $this->createStub(ChannelServiceActionsPort::class),
            userLookup: $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            notifier: $notifier ?? $this->createStub(OperServNotifierInterface::class),
            identifiedRegistry: $identifiedRegistry ?? new IdentifiedSessionRegistry(),
            nickAccounts: $nickAccounts ?? $this->createStub(NickAccountQuery::class),
            authorization: $authorization ?? $this->deniedAuthorization(),
            translator: $translator ?? $this->createStub(TranslatorInterface::class),
            defaultLanguage: 'en',
            debugChannel: $debugChannel,
        );
    }

    private function deniedAuthorization(): OperatorAuthorizationQuery
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('ircOperator')->willReturn(AuthorizationDecision::denied());

        return $authorization;
    }
}
