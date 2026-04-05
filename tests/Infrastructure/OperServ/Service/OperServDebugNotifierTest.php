<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Service;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\OperServ\Service\OperServDebugNotifier;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
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
    public function logWritesToFileWhenNotConfigured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('KILL', self::callback(static fn (array $context) => 'Admin' === $context['operator']
                && 'KILL' === $context['command']
                && 'BadUser' === $context['target']));

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createDebugNotifier(
            logger: $logger,
            notifier: $notifier,
            debugChannel: null,
        );

        $debug->log('Admin', 'KILL', 'BadUser');
    }

    #[Test]
    public function logWritesToFileAndChannelWhenConfigured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $debug = $this->createDebugNotifier(
            logger: $logger,
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->willReturnCallback(static fn (string $id, array $params) => $id);

        $debug = $this->createDebugNotifier(
            logger: $logger,
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('KILL', self::callback(static fn (array $context) => isset($context['extra'])
                && 'value' === $context['extra']['key']));

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createDebugNotifier(
            logger: $logger,
            notifier: $notifier,
            debugChannel: null,
        );

        $debug->log('Admin', 'KILL', 'BadUser', null, null, null, ['key' => 'value']);
    }

    #[Test]
    public function logForGlobalCommandUsesSpecialFormat(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

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
            logger: $logger,
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
        $rootRegistry = new RootUserRegistry('RootUser');

        $debug = $this->createDebugNotifier(
            rootRegistry: $rootRegistry,
        );

        self::assertTrue($debug->isIrcopOrRoot('RootUser', false));
    }

    #[Test]
    public function isIrcopOrRootReturnsTrueForIdentifiedIrcop(): void
    {
        $nick = RegisteredNick::createPending('OperUser', 'hash', 'test@test.com', 'en', new DateTimeImmutable('+1 day'));
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setAccessible(true);
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('OPER', 'Oper role');
        $ircop = OperIrcop::create(42, $role, 1, null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $debug = $this->createDebugNotifier(
            nickRepo: $nickRepo,
            ircopRepo: $ircopRepo,
        );

        self::assertTrue($debug->isIrcopOrRoot('OperUser', true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForNonOper(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $debug = $this->createDebugNotifier(
            nickRepo: $nickRepo,
        );

        self::assertFalse($debug->isIrcopOrRoot('NormalUser', false));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForOperNotIdentified(): void
    {
        $debug = $this->createDebugNotifier();

        self::assertFalse($debug->isIrcopOrRoot('SomeUser', false));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForIdentifiedNonIrcop(): void
    {
        $nick = RegisteredNick::createPending('NormalUser', 'hash', 'test@test.com', 'en', new DateTimeImmutable('+1 day'));
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setAccessible(true);
        $nickIdProp->setValue($nick, 42);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $debug = $this->createDebugNotifier(
            nickRepo: $nickRepo,
            ircopRepo: $ircopRepo,
        );

        self::assertFalse($debug->isIrcopOrRoot('NormalUser', true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForIdentifiedUserWithoutRegisteredNick(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $debug = $this->createDebugNotifier(
            nickRepo: $nickRepo,
        );

        self::assertFalse($debug->isIrcopOrRoot('UnknownUser', true));
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')
            ->willReturnCallback(static function (string $key, array $params = []): string {
                if ('debug.actionWithDuration' === $key) {
                    self::assertArrayHasKey('%duration%', $params);

                    return 'GLINE added with duration ' . $params['%duration%'];
                }

                return $key;
            });

        $debug = $this->createDebugNotifier(
            logger: $logger,
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

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
            logger: $logger,
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

    private function createDebugNotifier(
        ?ChannelServiceActionsPort $channelActions = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?OperServNotifierInterface $notifier = null,
        ?IdentifiedSessionRegistry $identifiedRegistry = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?RootUserRegistry $rootRegistry = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?TranslatorInterface $translator = null,
        ?LoggerInterface $logger = null,
        ?string $debugChannel = '#ircops',
    ): OperServDebugNotifier {
        return new OperServDebugNotifier(
            channelActions: $channelActions ?? $this->createStub(ChannelServiceActionsPort::class),
            userLookup: $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            notifier: $notifier ?? $this->createStub(OperServNotifierInterface::class),
            identifiedRegistry: $identifiedRegistry ?? new IdentifiedSessionRegistry(),
            ircopRepo: $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            rootRegistry: $rootRegistry ?? new RootUserRegistry(''),
            nickRepo: $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            translator: $translator ?? $this->createStub(TranslatorInterface::class),
            defaultLanguage: 'en',
            debugChannel: $debugChannel,
            logger: $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
