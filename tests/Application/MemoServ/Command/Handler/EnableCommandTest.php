<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\MemoServ\Command\Handler\EnableCommand;
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

#[CoversClass(EnableCommand::class)]
final class EnableCommandTest extends TestCase
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
            'ENABLE',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
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

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
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

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(null, $account, [], $notifier, $translator));

        self::assertSame([], $messages);
    }

    #[Test]
    public function enableNickRepliesAlreadyEnabledWhenAlreadyOn(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settings = $this->createStub(MemoSettings::class);
        $settings->method('isEnabled')->willReturn(true);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn($settings);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, [], $notifier, $translator));

        self::assertSame(['enable.already_enabled_nick'], $messages);
    }

    #[Test]
    public function enableNickWhenSettingsExistButDisabled(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settings = $this->createStub(MemoSettings::class);
        $settings->method('isEnabled')->willReturn(false);
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

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, [], $notifier, $translator));

        self::assertSame(['enable.enabled_nick'], $messages);
    }

    #[Test]
    public function enableNickSuccessSavesAndReplies(): void
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

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, [], $notifier, $translator));

        self::assertSame(['enable.enabled_nick'], $messages);
    }

    #[Test]
    public function enableChannelRepliesFounderOnlyWhenNotFounder(): void
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

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['enable.founder_only'], $messages);
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

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['enable.channel_not_registered'], $messages);
    }

    #[Test]
    public function replyAlreadyEnabledChannel(): void
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
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn($settings);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['enable.already_enabled_channel'], $messages);
    }

    #[Test]
    public function enableChannelWhenNoPriorSettingsExist(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn(null);
        $settingsRepo->expects(self::once())->method('save')->with(self::isInstanceOf(MemoSettings::class));

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['enable.enabled_channel'], $messages);
    }

    #[Test]
    public function enableChannelWhenSettingsExistButDisabled(): void
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

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['enable.enabled_channel'], $messages);
    }

    #[Test]
    public function enableChannelSuccessSavesAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn(null);
        $settingsRepo->expects(self::once())->method('save')->with(self::isInstanceOf(MemoSettings::class));

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['enable.enabled_channel'], $messages);
    }

    #[Test]
    public function getNameReturnsEnable(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame('ENABLE', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsEnableSyntax(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame('enable.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsEnableHelp(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame('enable.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturns6(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame(6, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsEnableShort(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame('enable.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $cmd = new EnableCommand($channelRepo, $settingsRepo);
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
