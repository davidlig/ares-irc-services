<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\SetCommand;
use App\Application\ChanServ\Command\Handler\SetDescHandler;
use App\Application\ChanServ\Command\Handler\SetEmailHandler;
use App\Application\ChanServ\Command\Handler\SetEntrymsgHandler;
use App\Application\ChanServ\Command\Handler\SetFounderHandler;
use App\Application\ChanServ\Command\Handler\SetMlockHandler;
use App\Application\ChanServ\Command\Handler\SetSecureHandler;
use App\Application\ChanServ\Command\Handler\SetSuccessorHandler;
use App\Application\ChanServ\Command\Handler\SetTopiclockHandler;
use App\Application\ChanServ\Command\Handler\SetUrlHandler;
use App\Application\ChanServ\FounderChangeTokenRegistry;
use App\Application\ChanServ\Service\MlockStateFromChannelResolver;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetCommand::class)]
final class SetCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'SET',
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

    private function createSetCommand(
        RegisteredChannelRepositoryInterface $channelRepo,
        ChanServAccessHelper $accessHelper,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
    ): SetCommand {
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo ??= $this->createStub(RegisteredNickRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $messageBus = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
        $trans = $this->createStub(TranslatorInterface::class);
        $trans->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        return new SetCommand(
            $channelRepo,
            $accessHelper,
            new SetFounderHandler(
                $channelRepo,
                $accessRepo,
                $nickRepo,
                new FounderChangeTokenRegistry(),
                $eventDispatcher,
                $messageBus,
                $trans,
                3600,
                600,
                3,
                $logger,
            ),
            new SetSuccessorHandler($channelRepo, $nickRepo),
            new SetDescHandler($channelRepo),
            new SetUrlHandler($channelRepo),
            new SetEmailHandler($channelRepo),
            new SetEntrymsgHandler($channelRepo),
            new SetTopiclockHandler($channelRepo),
            new SetMlockHandler($channelRepo, $eventDispatcher, new MlockStateFromChannelResolver()),
            new SetSecureHandler($channelRepo, $eventDispatcher),
        );
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['x', 'DESC', 'd'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['#test', 'DESC', 'd'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $account = $this->createStub(RegisteredNick::class);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'DESC', 'd'], $notifier, $translator));
    }

    #[Test]
    public function replyUnknownOptionWhenOptionNotSupported(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'UNKNOWN', 'v'], $notifier, $translator));

        self::assertSame(['set.unknown_option'], $messages);
    }

    #[Test]
    public function getNameReturnsSet(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame('SET', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsSetSyntax(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame('set.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsSetHelp(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame('set.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsFour(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame(4, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsSetShort(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame('set.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsExpectedOptions(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $help = $cmd->getSubCommandHelp();
        self::assertCount(9, $help);
        self::assertSame('FOUNDER', $help[0]['name']);
        self::assertSame('SUCCESSOR', $help[1]['name']);
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }

    #[Test]
    public function founderOptionThrowsWhenNotFounder(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(\App\Domain\ChanServ\Exception\InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'FOUNDER', 'NewFounder', 'token123'], $notifier, $translator));
    }

    #[Test]
    public function successorOptionThrowsWhenNotFounder(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(\App\Domain\ChanServ\Exception\InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SUCCESSOR', 'NewSuccessor'], $notifier, $translator));
    }

    #[Test]
    public function otherOptionRequiresSetLevel(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(\App\Domain\ChanServ\Exception\InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'DESC', 'New description'], $notifier, $translator));
    }

    #[Test]
    public function founderOptionPassesSingleArgumentToHandler(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(true);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $newFounder = $this->createStub(RegisteredNick::class);
        $newFounder->method('getStatus')->willReturn(\App\Domain\NickServ\ValueObject\NickStatus::Registered);
        $newFounder->method('getId')->willReturn(20);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('NewFounder')->willReturn($newFounder);
        $currentFounder = $this->createStub(RegisteredNick::class);
        $currentFounder->method('getEmail')->willReturn('founder@test.com');
        $nickRepo->method('findById')->willReturn($currentFounder);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $envelope = new \Symfony\Component\Messenger\Envelope(new stdClass());
        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturn($envelope);

        $cmd = new SetCommand(
            $channelRepo,
            $accessHelper,
            new SetFounderHandler(
                $channelRepo,
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $nickRepo,
                new FounderChangeTokenRegistry(),
                $this->createStub(EventDispatcherInterface::class),
                $messageBus,
                $translator,
                3600,
                600,
                3,
                $this->createStub(LoggerInterface::class),
            ),
            new SetSuccessorHandler($channelRepo, $nickRepo),
            new SetDescHandler($channelRepo),
            new SetUrlHandler($channelRepo),
            new SetEmailHandler($channelRepo),
            new SetEntrymsgHandler($channelRepo),
            new SetTopiclockHandler($channelRepo, $this->createStub(EventDispatcherInterface::class), new MlockStateFromChannelResolver()),
            new SetMlockHandler($channelRepo, $this->createStub(EventDispatcherInterface::class), new MlockStateFromChannelResolver()),
            new SetSecureHandler($channelRepo, $this->createStub(EventDispatcherInterface::class)),
        );
        $cmd->execute($this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $account,
            ['#test', 'FOUNDER', 'NewFounder'],
            $notifier,
            $translator,
        ));

        self::assertSame(['set.founder.token_sent'], $messages);
    }

    #[Test]
    public function founderOptionWithEmptyValueRepliesSyntax(): void
    {
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(true);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $account,
            ['#test', 'FOUNDER'],
            $notifier,
            $translator,
        ));

        self::assertSame(['set.founder.syntax'], $messages);
    }

    #[Test]
    public function nonFounderOptionJoinsAllArguments(): void
    {
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(false);
        $channel->expects(self::once())->method('updateDescription')->with('multi word description');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $level = new \App\Domain\ChanServ\Entity\ChannelLevel(1, \App\Domain\ChanServ\Entity\ChannelLevel::KEY_SET, 10);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($level);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $account,
            ['#test', 'DESC', 'multi', 'word', 'description'],
            $notifier,
            $translator,
        ));

        self::assertSame(['set.desc.updated'], $messages);
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
