<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\InfoCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(InfoCommand::class)]
final class InfoCommandTest extends TestCase
{
    private function createContext(
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['notachannel'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);

        $cmd->execute($this->createContext(['#test'], $notifier, $translator));
    }

    #[Test]
    public function successRepliesHeaderFounderAndFooter(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.header', $rawMessages);
        self::assertContains('info.founder', $rawMessages);
        self::assertContains('info.footer', $rawMessages);
    }

    #[Test]
    public function showsSuccessorWhenSet(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->assignSuccessor(2);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $successor = $this->createStub(RegisteredNick::class);
        $successor->method('getNickname')->willReturn('SuccessorNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnMap([[1, $founder], [2, $successor]]);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.successor', $rawMessages);
    }

    #[Test]
    public function showsDescriptionWhenSet(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'My Channel Description');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.description', $rawMessages);
    }

    #[Test]
    public function showsUrlWhenSet(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateUrl('https://example.com');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.url', $rawMessages);
    }

    #[Test]
    public function showsEmailWhenSet(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateEmail('test@example.com');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.email', $rawMessages);
    }

    #[Test]
    public function showsTopicWhenSet(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Welcome to the channel', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.topic', $rawMessages);
        self::assertContains('info.topic_set_by', $rawMessages);
    }

    #[Test]
    public function showsMlockWhenActive(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->configureMlock(true, '+nt');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.mlock_modes', $rawMessages);
    }

    #[Test]
    public function showsMlockNoModesWhenActiveButEmpty(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->configureMlock(true, '');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.mlock_modes', $rawMessages);
        $mlockLine = array_filter($rawMessages, static fn (string $m): bool => str_contains($m, 'info.mlock_modes'));
        self::assertNotEmpty($mlockLine);
    }

    #[Test]
    public function getNameReturnsInfo(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame('INFO', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsInfoSyntax(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame('info.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsInfoHelp(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame('info.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsFive(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame(5, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsInfoShort(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame('info.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function showsSuspendedStatusForSuspendedChannel(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->suspend('Abuse violation');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.suspended_status', $rawMessages);
        self::assertContains('info.suspended_reason', $rawMessages);
        self::assertContains('info.suspended_permanent', $rawMessages);
    }

    #[Test]
    public function showsSuspendedStatusWithExpiryForTimedSuspension(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->suspend('Spam', new DateTimeImmutable('+7 days'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.suspended_status', $rawMessages);
        self::assertContains('info.suspended_reason', $rawMessages);
        self::assertContains('info.suspended_until', $rawMessages);
    }

    #[Test]
    public function showsSuspendedStatusWithoutReasonWhenReasonIsNull(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->suspend('Abuse', null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('FounderNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.suspended_status', $rawMessages);
        self::assertContains('info.suspended_permanent', $rawMessages);
    }

    #[Test]
    public function allowsSuspendedChannelReturnsTrue(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        self::assertTrue($cmd->allowsSuspendedChannel());
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
