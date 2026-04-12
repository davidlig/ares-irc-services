<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\NoexpireCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NoexpireCommand::class)]
final class NoexpireCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsNoexpire(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('NOEXPIRE', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('noexpire.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('noexpire.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventySeven(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(76, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('noexpire.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNoexpirePermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(ChanServPermission::NOEXPIRE, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();
        $messages = [];
        $context = $this->createContext($this->createSender(), null, ['#test', 'ON'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function usesLevelFounderReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->usesLevelFounder());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $cmd = new NoexpireCommand($channelRepository);

        $messages = [];
        $context = $this->createContext(null, null, ['#test', 'ON'], $messages, channelRepository: $channelRepository);

        $cmd->execute($context);

        self::assertEmpty($messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithInvalidChannelNameRepliesInvalidChannel(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $cmd = new NoexpireCommand($channelRepository);

        $messages = [];
        $context = $this->createContext($this->createSender(), null, ['notachannel', 'ON'], $messages, channelRepository: $channelRepository);

        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithInvalidActionRepliesSyntaxError(): void
    {
        $messages = [];
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);

        $context = $this->createContext($this->createSender(), null, ['#test', 'INVALID'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithNotRegisteredChannelRepliesNotRegistered(): void
    {
        $messages = [];
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $context = $this->createContext($this->createSender(), null, ['#test', 'ON'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertContains('noexpire.not_registered', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithForbiddenChannelRepliesForbidden(): void
    {
        $channel = RegisteredChannel::createForbidden('#forbidden', 'Banned');

        $messages = [];
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $context = $this->createContext($this->createSender(), null, ['#forbidden', 'ON'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertContains('noexpire.forbidden', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithSuspendedChannelRepliesSuspended(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channel->suspend('Bad behavior');

        $messages = [];
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $context = $this->createContext($this->createSender(), null, ['#test', 'ON'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertContains('noexpire.suspended', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithOnSetsNoExpireAndRepliesSuccessOn(): void
    {
        $channel = $this->createChannelWithId('#test', 1);

        self::assertFalse($channel->isNoExpire(), 'noExpire should be false by default');

        $messages = [];
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $context = $this->createContext($this->createSender(), null, ['#test', 'ON'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertTrue($channel->isNoExpire(), 'noExpire should be true after ON');
        self::assertContains('noexpire.success_on', $messages);

        $audit = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $audit);
        self::assertSame('#test', $audit->target);
        self::assertSame(['option' => 'ON'], $audit->extra);
    }

    #[Test]
    public function executeWithOffClearsNoExpireAndRepliesSuccessOff(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channel->setNoExpire(true);

        self::assertTrue($channel->isNoExpire(), 'noExpire should be true before OFF');

        $messages = [];
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $context = $this->createContext($this->createSender(), null, ['#test', 'OFF'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertFalse($channel->isNoExpire(), 'noExpire should be false after OFF');
        self::assertContains('noexpire.success_off', $messages);

        $audit = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $audit);
        self::assertSame('#test', $audit->target);
        self::assertSame(['option' => 'OFF'], $audit->extra);
    }

    #[Test]
    public function executeWithLowerCaseOnWorks(): void
    {
        $channel = $this->createChannelWithId('#test', 1);

        $messages = [];
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $context = $this->createContext($this->createSender(), null, ['#test', 'on'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertTrue($channel->isNoExpire());
        self::assertContains('noexpire.success_on', $messages);
    }

    #[Test]
    public function executeWithLowerCaseOffWorks(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channel->setNoExpire(true);

        $messages = [];
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $context = $this->createContext($this->createSender(), null, ['#test', 'off'], $messages, channelRepository: $channelRepository);

        $cmd = new NoexpireCommand($channelRepository);
        $cmd->execute($context);

        self::assertFalse($channel->isNoExpire());
        self::assertContains('noexpire.success_off', $messages);
    }

    private function createCommand(): NoexpireCommand
    {
        return new NoexpireCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'TestOper', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    }

    private function createChannelWithId(string $name, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($name, 1, 'Test description');

        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($channel, $id);

        return $channel;
    }

    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
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
            'NOEXPIRE',
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
}
