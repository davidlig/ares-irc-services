<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\LevelsCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevels;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsHandler;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsResult;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(LevelsCommand::class)]
#[CoversClass(ManageChannelLevels::class)]
#[CoversClass(ManageChannelLevelsHandler::class)]
#[CoversClass(ManageChannelLevelsResult::class)]
final class LevelsCommandTest extends TestCase
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
            'LEVELS',
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
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), new ChanAccountView(1, 'User', 'en'), ['x', 'LIST'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $this->expectException(ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'LIST'], $notifier, $translator));
    }

    #[Test]
    public function throwsInsufficientAccessWhenNotFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $account = new ChanAccountView(2, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $this->expectException(InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'LIST'], $notifier, $translator));
    }

    #[Test]
    public function replyUnknownSubWhenSubCommandInvalid(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'INVALID'], $notifier, $translator));

        self::assertSame(['levels.unknown_sub'], $messages);
    }

    #[Test]
    public function invalidSubcommandStillChecksChannelRegistrationFirst(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
        $command = new LevelsCommand(new ManageChannelLevelsHandler(
            $channelRepo,
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        $this->expectException(ChannelNotRegisteredException::class);
        $command->execute($this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            new ChanAccountView(1, 'User', 'en'),
            ['#missing', 'INVALID'],
            $this->createStub(ChanServNotifierInterface::class),
            $translator,
        ));
    }

    #[Test]
    public function invalidSubcommandStillChecksIdentificationFirst(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
        $command = new LevelsCommand(new ManageChannelLevelsHandler(
            $channelRepo,
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        $command->execute($this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            ['#test', 'INVALID'],
            $notifier,
            $translator,
        ));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function invalidSubcommandStillChecksFounderAuthorizationFirst(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $command = new LevelsCommand(new ManageChannelLevelsHandler(
            $channelRepo,
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        $this->expectException(InsufficientAccessException::class);
        $command->execute($this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', 'INVALID'],
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslationInterface::class),
        ));
    }

    #[Test]
    public function listShowsHeaderAndLevelLines(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('listByChannel')->willReturn([]);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame('levels.list.header', $messages[0]);
        self::assertGreaterThan(1, count($messages));
    }

    #[Test]
    public function listShowsNojoinLevel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('listByChannel')->willReturn([]);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'LIST'], $notifier, $translator));

        $foundNojoin = false;
        foreach ($messages as $msg) {
            if (str_contains($msg, 'NOJOIN') && str_contains($msg, '-1')) {
                $foundNojoin = true;
                break;
            }
        }
        self::assertTrue($foundNojoin, 'NOJOIN level with default -1 should be displayed');
    }

    #[Test]
    public function setRepliesSyntaxWhenKeyOrValueMissing(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SET', 'AUTOOP', ''], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function setRepliesUnknownKeyWhenKeyNotVisible(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SET', 'UNKNOWNKEY', '100'], $notifier, $translator));

        self::assertSame(['levels.unknown_key'], $messages);
    }

    #[Test]
    public function setSavesAndRepliesDone(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $levelRepo->expects(self::once())->method('save')->with(self::isInstanceOf(ChannelLevel::class));
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SET', 'AUTOOP', '200'], $notifier, $translator));

        self::assertSame(['levels.set.done'], $messages);
    }

    #[Test]
    public function resetRemovesAllAndRepliesDone(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('removeAllForChannel')->with(1);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'RESET'], $notifier, $translator));

        self::assertSame(['levels.reset.done'], $messages);
    }

    #[Test]
    public function setLevelValueOutOfRangeTooLow(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SET', 'AUTOOP', '-5'], $notifier, $translator));

        self::assertSame(['levels.value_range'], $messages);
    }

    #[Test]
    public function setLevelValueOutOfRangeTooHigh(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SET', 'AUTOOP', '9999'], $notifier, $translator));

        self::assertSame(['levels.value_range'], $messages);
    }

    #[Test]
    public function setLevelUpdatesExistingLevel(): void
    {
        $existingLevel = $this->createMock(ChannelLevel::class);
        $existingLevel->expects(self::once())->method('updateLevelValue')->with(250);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($existingLevel);
        $levelRepo->expects(self::once())->method('save')->with($existingLevel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'SET', 'AUTOOP', '250'], $notifier, $translator));

        self::assertSame(['levels.set.done'], $messages);
    }

    #[Test]
    public function listFiltersAdminLevelsWithNullChannelModeSupport(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('listByChannel')->willReturn([]);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame('levels.list.header', $messages[0]);
        foreach ($messages as $msg) {
            self::assertStringNotContainsString('AUTOADMIN', $msg);
            self::assertStringNotContainsString('ADMINDEADMIN', $msg);
            self::assertStringNotContainsString('AUTOHALFOP', $msg);
            self::assertStringNotContainsString('HALFOPDEHALFOP', $msg);
        }
    }

    #[Test]
    public function listWithEntriesShowsStoredValues(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $account = new ChanAccountView(1, 'User', 'en');
        $levelAutoop = $this->createStub(ChannelLevel::class);
        $levelAutoop->method('getLevelKey')->willReturn('AUTOOP');
        $levelAutoop->method('getValue')->willReturn(250);
        $levelInvite = $this->createStub(ChannelLevel::class);
        $levelInvite->method('getLevelKey')->willReturn('INVITE');
        $levelInvite->method('getValue')->willReturn(100);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('listByChannel')->willReturn([$levelAutoop, $levelInvite]);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new LevelsCommand(new ManageChannelLevelsHandler($channelRepo, $levelRepo));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame('levels.list.header', $messages[0]);
        $foundAutoop = false;
        $foundInvite = false;
        foreach ($messages as $msg) {
            if (str_contains($msg, 'AUTOOP') && str_contains($msg, '250')) {
                $foundAutoop = true;
            }
            if (str_contains($msg, 'INVITE') && str_contains($msg, '100')) {
                $foundInvite = true;
            }
        }
        self::assertTrue($foundAutoop, 'AUTOOP level should be displayed');
        self::assertTrue($foundInvite, 'INVITE level should be displayed');
    }

    #[Test]
    public function getNameReturnsLevels(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame('LEVELS', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsLevelsSyntax(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame('levels.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsLevelsHelp(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame('levels.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsNine(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame(9, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsLevelsShort(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame('levels.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsArrayWithListSetReset(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        $help = $cmd->getSubCommandHelp();

        self::assertSame([
            ['name' => 'LIST', 'desc_key' => 'levels.list.short', 'help_key' => 'levels.list.help', 'syntax_key' => 'levels.list.syntax'],
            ['name' => 'SET', 'desc_key' => 'levels.set.short', 'help_key' => 'levels.set.help', 'syntax_key' => 'levels.set.syntax'],
            ['name' => 'RESET', 'desc_key' => 'levels.reset.short', 'help_key' => 'levels.reset.help', 'syntax_key' => 'levels.reset.syntax'],
        ], $help);
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = new LevelsCommand(new ManageChannelLevelsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        ));

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
