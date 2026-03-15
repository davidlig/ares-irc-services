<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\MemoServ\Command\Handler\DisableCommand;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\MemoSettings;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DisableCommand::class)]
final class DisableCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        MemoServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): MemoServContext {
        return new MemoServContext(
            $sender,
            $senderAccount,
            'DISABLE',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
        );
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, [], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(null, $account, [], $notifier, $translator));

        self::assertSame([], $messages);
    }

    #[Test]
    public function disableNickRepliesAlreadyDisabledWhenAlreadyOff(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settings = $this->createStub(MemoSettings::class);
        $settings->method('isEnabled')->willReturn(false);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn($settings);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, [], $notifier, $translator));

        self::assertSame(['disable.already_disabled_nick'], $messages);
    }

    #[Test]
    public function disableNickWhenNoPriorSettingsExist(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn(null);
        $settingsRepo->expects(self::once())->method('save')->with(self::isInstanceOf(MemoSettings::class));

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, [], $notifier, $translator));

        self::assertSame(['disable.disabled_nick'], $messages);
    }

    #[Test]
    public function disableNickSuccessSavesAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settings = $this->createStub(MemoSettings::class);
        $settings->method('isEnabled')->willReturn(true);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn($settings);
        $settingsRepo->expects(self::once())->method('save')->with($settings);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, [], $notifier, $translator));

        self::assertSame(['disable.disabled_nick'], $messages);
    }

    #[Test]
    public function disableChannelRepliesFounderOnlyWhenNotFounder(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['disable.founder_only'], $messages);
    }

    #[Test]
    public function replyChannelNotRegistered(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['disable.channel_not_registered'], $messages);
    }

    #[Test]
    public function replyAlreadyDisabledChannel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $settings = $this->createStub(MemoSettings::class);
        $settings->method('isEnabled')->willReturn(false);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn($settings);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['disable.already_disabled_channel'], $messages);
    }

    #[Test]
    public function disableChannelSuccessSavesAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $settings = $this->createStub(MemoSettings::class);
        $settings->method('isEnabled')->willReturn(true);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn($settings);
        $settingsRepo->expects(self::once())->method('save')->with($settings);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['disable.disabled_channel'], $messages);
    }

    #[Test]
    public function getNameReturnsDisable(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame('DISABLE', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsDisableSyntax(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame('disable.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsDisableHelp(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame('disable.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturns7(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame(7, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsDisableShort(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame('disable.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new DisableCommand($channelRepo, $settingsRepo);
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }
}
