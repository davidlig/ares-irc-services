<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\UnsuspendCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnsuspendCommand::class)]
final class UnsuspendCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsUnsuspend(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('UNSUSPEND', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unsuspend.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unsuspend.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsExpected(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(78, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unsuspend.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsSuspendPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(ChanServPermission::SUSPEND, $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function executeWithInvalidChannelRepliesInvalidChannel(): void
    {
        $sender = $this->createSender();
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $cmd = new UnsuspendCommand(
            $channelRepository,
            $this->createStub(EventBusInterface::class),
        );

        $messages = [];
        $context = $this->createContext($sender, null, ['notachannel'], $messages);

        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeWithNonRegisteredChannelRepliesNotRegistered(): void
    {
        $sender = $this->createSender();
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $cmd = new UnsuspendCommand(
            $channelRepository,
            $this->createStub(EventBusInterface::class),
        );

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd->execute($context);

        self::assertContains('unsuspend.not_registered', $messages);
    }

    #[Test]
    public function executeWithNotSuspendedChannelRepliesNotSuspended(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $cmd = new UnsuspendCommand(
            $channelRepository,
            $this->createStub(EventBusInterface::class),
        );

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd->execute($context);

        self::assertContains('unsuspend.not_suspended', $messages);
    }

    #[Test]
    public function executeWithSuspendedChannelUnsuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ChannelUnsuspendedEvent::class));

        $cmd = new UnsuspendCommand($channelRepository, $eventDispatcher);

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd->execute($context);

        self::assertFalse($channel->isSuspended());
        self::assertContains('unsuspend.success', $messages);
    }

    #[Test]
    public function executeWithNullSenderDoesNothing(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $cmd = new UnsuspendCommand($channelRepository, $eventDispatcher);

        $messages = [];
        $context = $this->createContext(null, null, ['#test'], $messages);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeReturnsRejectedWhenValidatedChannelHasNoSender(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $messages = [];

        $outcome = new UnsuspendCommand($channelRepository, $this->createStub(EventBusInterface::class))
            ->execute($this->createContext(null, null, ['#test'], $messages, $channelRepository));

        self::assertFalse($outcome->success);
        self::assertEmpty($messages);
    }

    #[Test]
    public function getAuditDataReturnsDataAfterExecute(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $cmd = new UnsuspendCommand(
            $channelRepository,
            $this->createStub(EventBusInterface::class),
        );

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $outcome = $cmd->execute($context);

        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
    }

    private function createCommand(): UnsuspendCommand
    {
        return new UnsuspendCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(EventBusInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o');
    }

    /**
     * @param array<string> $args
     * @param array<string> $messages
     */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        array &$messages,
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            $sender,
            $senderAccount,
            'UNSUSPEND',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }

    #[Test]
    public function executeWithEmptyIpBase64DecodesAsAsterisk(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', '', false, true, 'SID1', 'h', 'o');
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        /** @var list<object> $dispatchedEvents */
        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use (&$dispatchedEvents): ChannelUnsuspendedEvent {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd = new UnsuspendCommand($channelRepository, $eventDispatcher);
        $cmd->execute($context);

        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(ChannelUnsuspendedEvent::class, $dispatchedEvents[0]);
        self::assertSame('*', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function executeWithInvalidBase64IpFallsBackToRawString(): void
    {
        $invalidBase64 = '!!!invalid!!!';
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', $invalidBase64, false, true, 'SID1', 'h', 'o');
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        /** @var list<object> $dispatchedEvents */
        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (ChannelUnsuspendedEvent $event) use (&$dispatchedEvents): ChannelUnsuspendedEvent {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd = new UnsuspendCommand($channelRepository, $eventDispatcher);
        $cmd->execute($context);

        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(ChannelUnsuspendedEvent::class, $dispatchedEvents[0]);
        self::assertSame($invalidBase64, $dispatchedEvents[0]->performedByIp);
    }
}
