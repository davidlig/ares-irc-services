<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetCommand;
use App\ChanServ\Adapter\In\Irc\Command\SetDescHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetEmailHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetEntrymsgHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetFounderHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetMlockHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetSecureHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetSuccessorHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetTopiclockHandler;
use App\ChanServ\Adapter\In\Irc\Command\SetUrlHandler;
use App\ChanServ\Adapter\In\Irc\MlockStateFromChannelResolver;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecureHandlerInterface;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounder;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderHandlerInterface;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderResult;
use App\ChanServ\Application\UseCase\TransferFounder\TransferFounderOutcome;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetCommand::class)]
final class SetCommandTest extends TestCase
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
        ?ChanUserAccountPort $nickRepo = null,
    ): SetCommand {
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo ??= $this->createStub(ChanUserAccountPort::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $trans = $this->createStub(TranslationInterface::class);
        $trans->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new SetCommand(
            $channelRepo,
            $accessHelper,
            $this->createFounderHandler(),
            new SetSuccessorHandler($channelRepo, $nickRepo, $this->createStub(EventBusInterface::class)),
            new SetDescHandler($channelRepo),
            new SetUrlHandler($channelRepo),
            new SetEmailHandler($channelRepo),
            new SetEntrymsgHandler($channelRepo),
            new SetTopiclockHandler($channelRepo, $eventDispatcher),
            new SetMlockHandler($this->createStub(ConfigureChannelMlockHandlerInterface::class), new MlockStateFromChannelResolver()),
            new SetSecureHandler($this->createStub(ConfigureChannelSecureHandlerInterface::class)),
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), new ChanAccountView(1, 'User', 'en'), ['x', 'DESC', 'd'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
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
        $translator = $this->createStub(TranslationInterface::class);
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
        $account = new ChanAccountView(1, 'User', 'en');
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'DESC', 'd'], $notifier, $translator));
    }

    #[Test]
    public function replyUnknownOptionWhenOptionNotSupported(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = new ChanAccountView(2, 'User', 'en');

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
        $channel = $this->createStub(RegisteredChannel::class);
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = new ChanAccountView(2, 'User', 'en');

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'FOUNDER', 'NewFounder', 'token123'], $notifier, $translator));
    }

    #[Test]
    public function successorOptionThrowsWhenNotFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = new ChanAccountView(2, 'User', 'en');

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SUCCESSOR', 'NewSuccessor'], $notifier, $translator));
    }

    #[Test]
    public function otherOptionRequiresSetLevel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = new ChanAccountView(2, 'User', 'en');

        $cmd = $this->createSetCommand($channelRepo, $accessHelper);
        $this->expectException(InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'DESC', 'New description'], $notifier, $translator));
    }

    #[Test]
    public function founderOptionPassesSingleArgumentToHandler(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(true);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $accessHelper = new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = new ChanAccountView(10, 'User', 'en');
        $founderHandler = $this->createMock(TransferChannelFounderHandlerInterface::class);
        $founderHandler->expects(self::once())->method('handle')->with(self::callback(
            static fn (TransferChannelFounder $command): bool => 'NewFounder' === $command->targetNickname,
        ))->willReturn(new TransferChannelFounderResult(TransferFounderOutcome::TokenSent, emailHint: 'fo***@test.com'));

        $cmd = new SetCommand(
            $channelRepo,
            $accessHelper,
            new SetFounderHandler($founderHandler),
            new SetSuccessorHandler($channelRepo, $nickRepo, $this->createStub(EventBusInterface::class)),
            new SetDescHandler($channelRepo),
            new SetUrlHandler($channelRepo),
            new SetEmailHandler($channelRepo),
            new SetEntrymsgHandler($channelRepo),
            new SetTopiclockHandler($channelRepo, $this->createStub(EventBusInterface::class)),
            new SetMlockHandler($this->createStub(ConfigureChannelMlockHandlerInterface::class), new MlockStateFromChannelResolver()),
            new SetSecureHandler($this->createStub(ConfigureChannelSecureHandlerInterface::class)),
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
        $channel = $this->createStub(RegisteredChannel::class);
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = new ChanAccountView(10, 'User', 'en');

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
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(false);
        $channel->expects(self::once())->method('updateDescription')->with('multi word description');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $level = new ChannelLevel(1, ChannelLevel::KEY_SET, 10);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($level);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $account = new ChanAccountView(10, 'User', 'en');

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

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = $this->createSetCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    private function createFounderHandler(): SetFounderHandler
    {
        $handler = $this->createStub(TransferChannelFounderHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static fn (TransferChannelFounder $command): TransferChannelFounderResult => new TransferChannelFounderResult(
                '' === $command->targetNickname
                    ? TransferFounderOutcome::MissingTarget
                    : TransferFounderOutcome::Ignored,
            ),
        );

        return new SetFounderHandler($handler);
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
