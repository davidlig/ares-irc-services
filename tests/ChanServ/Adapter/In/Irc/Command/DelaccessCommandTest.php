<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\DelaccessCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelAccessChangedEvent;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DelaccessCommand::class)]
final class DelaccessCommandTest extends TestCase
{
    /** @param array<string> $args */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'DELACCESS',
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

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), new ChanAccountView(1, 'User', 'en'), ['notachannel'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['#test'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function executeReturnsWithoutChangingAccessWhenSenderIsNull(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(2, 'User', 'en');
        $accessEntry = $this->createStub(ChannelAccess::class);
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepository->method('findByChannelAndNick')->willReturn($accessEntry);
        $accessRepository->expects(self::never())->method('remove');
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $command = new DelaccessCommand($channelRepository, $accessRepository, $eventDispatcher);
        $command->execute($this->createContext(null, $account, ['#test'], $notifier, $this->createStub(TranslationInterface::class)));
    }

    #[Test]
    public function replyFounderNotInAccessWhenSenderIsFounder(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['delaccess.founder_not_in_access'], $messages);
    }

    #[Test]
    public function replyNotInListWhenNoAccessEntry(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(2, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['delaccess.not_in_list'], $messages);
    }

    #[Test]
    public function successRemovesAccessAndRepliesAndSendsNoticeToChannel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(2, 'User', 'en');
        $accessEntry = $this->createStub(ChannelAccess::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($accessEntry);
        $accessRepo->expects(self::once())->method('remove')->with($accessEntry);
        $messages = [];
        $noticesToChannel = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $ch, string $m) use (&$noticesToChannel): void {
            $noticesToChannel[] = [$ch, $m];
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['delaccess.done'], $messages);
        self::assertCount(1, $noticesToChannel);
        self::assertSame('#test', $noticesToChannel[0][0]);
    }

    #[Test]
    public function successWithWildcardIpDispatchesEventWithStarIp(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(2, 'User', 'en');
        $accessEntry = $this->createStub(ChannelAccess::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($accessEntry);
        $accessRepo->expects(self::once())->method('remove');
        $dispatchedIp = '';
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (ChannelAccessChangedEvent $e) use (&$dispatchedIp): ChannelAccessChangedEvent {
            $dispatchedIp = $e->performedByIp;

            return $e;
        });
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $eventDispatcher);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', '*'), $account, ['#test'], $notifier, $translator));

        self::assertSame('*', $dispatchedIp);
    }

    #[Test]
    public function successWithInvalidBase64IpDispatchesEventWithRawIp(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(2, 'User', 'en');
        $accessEntry = $this->createStub(ChannelAccess::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($accessEntry);
        $accessRepo->expects(self::once())->method('remove');
        $dispatchedIp = '';
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (ChannelAccessChangedEvent $e) use (&$dispatchedIp): ChannelAccessChangedEvent {
            $dispatchedIp = $e->performedByIp;

            return $e;
        });
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $eventDispatcher);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', '!!!invalid!!!'), $account, ['#test'], $notifier, $translator));

        self::assertSame('!!!invalid!!!', $dispatchedIp);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $account = new ChanAccountView(2, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $this->expectException(ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));
    }

    #[Test]
    public function successDeletesAccessInChannelContext(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(2, 'User', 'en');
        $accessEntry = $this->createStub(ChannelAccess::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($accessEntry);
        $accessRepo->expects(self::once())->method('remove')->with($accessEntry);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#mychannel'], $notifier, $translator));

        self::assertStringContainsString('delaccess.done', $messages[0]);
    }

    #[Test]
    public function replyNotInListWhenNickNotInAccessList(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(99, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $accessRepo->expects(self::never())->method('remove');
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['delaccess.not_in_list'], $messages);
    }

    #[Test]
    public function founderCannotDeleteOwnAccessAsFounderNotInAccessList(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $founderAccount = new ChanAccountView(1, 'Founder', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip'), $founderAccount, ['#test'], $notifier, $translator));

        self::assertSame(['delaccess.founder_not_in_access'], $messages);
    }

    #[Test]
    public function getNameReturnsDelaccess(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame('DELACCESS', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsDelaccessSyntax(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame('delaccess.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsDelaccessHelp(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame('delaccess.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturns9(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame(9, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsDelaccessShort(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame('delaccess.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $cmd = new DelaccessCommand($channelRepo, $accessRepo, $this->createStub(EventBusInterface::class));

        self::assertFalse($cmd->allowsForbiddenChannel());
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
}
