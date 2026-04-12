<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\UnsuspendCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
        $idProp->setAccessible(true);
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
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

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $cmd = new UnsuspendCommand($channelRepository, $eventDispatcher);

        $messages = [];
        $context = $this->createContext(null, null, ['#test'], $messages);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();
        $messages = [];

        self::assertNull($cmd->getAuditData($this->createContext(null, null, [], $messages)));
    }

    #[Test]
    public function getAuditDataReturnsDataAfterExecute(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $cmd = new UnsuspendCommand(
            $channelRepository,
            $this->createStub(EventDispatcherInterface::class),
        );

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
    }

    private function createCommand(): UnsuspendCommand
    {
        return new UnsuspendCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    }

    private function createContext(
        ?SenderView $sender,
        ?\App\Domain\NickServ\Entity\RegisteredNick $senderAccount,
        array $args,
        array &$messages,
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', '', false, true, 'SID1', 'h', 'o', '');
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
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
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', $invalidBase64, false, true, 'SID1', 'h', 'o', '');
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('Abuse', null);
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, 1);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd = new UnsuspendCommand($channelRepository, $eventDispatcher);
        $cmd->execute($context);

        self::assertCount(1, $dispatchedEvents);
        self::assertSame($invalidBase64, $dispatchedEvents[0]->performedByIp);
    }
}
