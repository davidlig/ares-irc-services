<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\OpCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
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

#[CoversClass(OpCommand::class)]
final class OpCommandTest extends TestCase
{
    /**
     * @param string[] $args
     */
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
            'OP',
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
    public function executeReturnsEarlyWhenSenderIsNull(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslationInterface::class);

        $command = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        $command->execute($this->createContext(null, null, [], $notifier, $translator));
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), new ChanAccountView(1, 'User', 'en'), ['x', 'SomeNick'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['#test', 'OpNick'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyNickNotRegisteredWhenTargetNotRegistered(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $account = new ChanAccountView(1, 'User', 'en');
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'UnknownNick'], $notifier, $translator));

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function replyUserNotOnChannelWhenTargetNotOnNetwork(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isSecure')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $targetAccount = new ChanAccountView(2, 'TargetNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($targetAccount);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $account = new ChanAccountView(1, 'User', 'en');
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['op.user_not_on_channel'], $messages);
    }

    #[Test]
    public function successSetsModeAndReplies(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isSecure')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $targetAccount = new ChanAccountView(2, 'TargetNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($targetAccount);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip'));
        $account = new ChanAccountView(1, 'User', 'en');
        $messages = [];
        $modeCalls = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelMemberMode')->willReturnCallback(static function (string $ch, string $uid, string $letter, bool $add) use (&$modeCalls): void {
            $modeCalls[] = [$ch, $uid, $letter, $add];
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['op.done'], $messages);
        self::assertCount(1, $modeCalls);
        self::assertSame(['#test', 'UID2', 'o', true], $modeCalls[0]);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $this->expectException(ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), new ChanAccountView(1, 'User', 'en'), ['#test', 'Nick'], $notifier, $translator));
    }

    #[Test]
    public function replySyntaxErrorWhenEmptyTargetNick(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), new ChanAccountView(1, 'User', 'en'), ['#test', ''], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function throwsInsufficientAccessWhenSenderNotAuthorized(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isSecure')->willReturn(false);
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $account = new ChanAccountView(1, 'User', 'en');
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $this->expectException(InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));
    }

    #[Test]
    public function replyErrorWhenSecureChannelAndTargetInsufficientLevel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isSecure')->willReturn(true);
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $senderAccess = $this->createStub(ChannelAccess::class);
        $senderAccess->method('getLevel')->willReturn(300);
        $accessRepo->method('findByChannelAndNick')->willReturnOnConsecutiveCalls($senderAccess, null);
        $level = $this->createStub(ChannelLevel::class);
        $level->method('getValue')->willReturn(50);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn($level);
        $targetAccount = new ChanAccountView(2, 'TargetNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($targetAccount);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip'));
        $account = new ChanAccountView(1, 'User', 'en');
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['secure.requires_min_level'], $messages);
    }

    #[Test]
    public function successWhenSenderIsFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isSecure')->willReturn(false);
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $targetAccount = new ChanAccountView(2, 'TargetNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($targetAccount);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip'));
        $account = new ChanAccountView(1, 'User', 'en');
        $messages = [];
        $modeCalls = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelMemberMode')->willReturnCallback(static function (string $ch, string $uid, string $letter, bool $add) use (&$modeCalls): void {
            $modeCalls[] = [$ch, $uid, $letter, $add];
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new OpCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['op.done'], $messages);
        self::assertCount(1, $modeCalls);
    }

    #[Test]
    public function getNameReturnsOp(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame('OP', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsOpSyntax(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame('op.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsOpHelp(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame('op.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsTwenty(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame(20, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsOpShort(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame('op.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = new OpCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

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
