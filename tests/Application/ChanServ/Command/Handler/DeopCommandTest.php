<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\DeopCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DeopCommand::class)]
final class DeopCommandTest extends TestCase
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
            'DEOP',
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
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['x', 'Nick'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyUserNotOnChannelWhenTargetNotOnNetwork(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['op.user_not_on_channel'], $messages);
    }

    #[Test]
    public function successRemovesModeAndReplies(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getLevel')->willReturn(300);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip'));
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['deop.done'], $messages);
        self::assertSame(['#test', 'UID2', 'o', false], $modeCalls[0]);
    }

    #[Test]
    public function replySyntaxErrorWhenTargetNickEmpty(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand(
            $channelRepo,
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['#test', ''], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function throwsChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand(
            $channelRepo,
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);

        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['#test', 'Nick'], $notifier, $translator));
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand(
            $channelRepo,
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['#test', 'Nick'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function throwsInsufficientAccessWhenSenderLevelTooLow(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $senderAccess = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $senderAccess->method('getLevel')->willReturn(10);
        $accessRepo->method('findByChannelAndNick')->willReturn($senderAccess);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $cmd = new DeopCommand(
            $channelRepo,
            $accessRepo,
            $levelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
        $this->expectException(\App\Domain\ChanServ\Exception\InsufficientAccessException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));
    }

    #[Test]
    public function replyInsufficientAccessWhenSenderLevelNotGreaterThanTarget(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $senderAccess = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $senderAccess->method('getLevel')->willReturn(300);
        $accessRepo->method('findByChannelAndNick')->willReturn($senderAccess);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);

        $targetAccount = $this->createStub(RegisteredNick::class);
        $targetAccount->method('getId')->willReturn(2);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($targetAccount);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip'));

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand(
            $channelRepo,
            $accessRepo,
            $levelRepo,
            $nickRepo,
            $userLookup,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['error.insufficient_access'], $messages);
    }

    #[Test]
    public function successWhenDeoppingSelf(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getLevel')->willReturn(100);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturnCallback(function (int $channelId, string $key) {
            if (\App\Domain\ChanServ\Entity\ChannelLevel::KEY_OPDEOP === $key) {
                $level = $this->createStub(\App\Domain\ChanServ\Entity\ChannelLevel::class);
                $level->method('getValue')->willReturn(50);

                return $level;
            }

            return null;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturnCallback(static fn (string $nick) => 'User' === $nick ? $account : null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturnCallback(static fn (string $nick) => 'User' === $nick ? new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip') : null);

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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'User'], $notifier, $translator));

        self::assertSame(['deop.done'], $messages);
        self::assertSame(['#test', 'UID1', 'o', false], $modeCalls[0]);
    }

    #[Test]
    public function successWhenSenderIsFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturnMap([[1, true]]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip'));

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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DeopCommand($channelRepo, $accessRepo, $levelRepo, $nickRepo, $userLookup);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['deop.done'], $messages);
        self::assertSame(['#test', 'UID2', 'o', false], $modeCalls[0]);
    }

    #[Test]
    public function getNameReturnsDeop(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame('DEOP', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsDeopSyntax(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame('deop.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsDeopHelp(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame('deop.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsTwentyOne(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame(21, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsDeopShort(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame('deop.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $cmd = new DeopCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
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
