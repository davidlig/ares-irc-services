<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\InfoCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(InfoCommand::class)]
final class InfoCommandTest extends TestCase
{
    /**
     * @param string[] $args
     */
    private function createContext(
        array $args,
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
        ?ChannelLookupPort $channelLookup = null,
        ?ChanAccountView $senderAccount = null,
        ?SenderView $sender = null,
    ): ChanServContext {
        return new ChanServContext(
            $sender ?? new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $senderAccount,
            'INFO',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['notachannel'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function showsPendingDeletionStatus(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->markPendingDeletion(new DateTimeImmutable('2026-05-01 12:00:00'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo, 7);
        $cmd->execute($this->createContext(['#test'], $notifier, $translator));

        self::assertContains('info.pending_deletion_status', $messages);
        self::assertContains('info.pending_deletion_at', $messages);
        self::assertContains('info.pending_deletion_until', $messages);
        self::assertContains('info.pending_deletion_notice', $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);

        $this->expectException(ChannelNotRegisteredException::class);

        $cmd->execute($this->createContext(['#test'], $notifier, $translator));
    }

    #[Test]
    public function successRepliesHeaderFounderAndFooter(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $successor = new ChanAccountView(2, 'SuccessorNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturnMap([[1, $founder], [2, $successor]]);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame('INFO', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsInfoSyntax(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame('info.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsInfoHelp(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame('info.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsTwo(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame(2, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsInfoShort(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame('info.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertTrue($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsTrue(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        self::assertTrue($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function showsForbiddenStatusForForbiddenChannel(): void
    {
        $channel = RegisteredChannel::createForbidden('#Test', 'Spam channel');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = 'chanserv', string $locale = 'en'): string => $id);

        $cmd = new InfoCommand($channelRepo, $this->createStub(ChanUserAccountPort::class));
        $context = $this->createContext(['#Test'], $notifier, $translator);
        $cmd->execute($context);

        self::assertContains('info.header', $rawMessages);
        self::assertContains('info.forbidden_status', $rawMessages);
        self::assertContains('info.forbidden_reason', $rawMessages);
        self::assertContains('info.footer', $rawMessages);
        self::assertNotContains('info.founder', $rawMessages);
    }

    #[Test]
    public function showsForbiddenStatusWithoutReasonWhenReasonIsNull(): void
    {
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $channel = RegisteredChannel::createForbidden('#Test', 'Some reason');
        $reasonProp = $reflection->getProperty('forbiddenReason');
        $reasonProp->setValue($channel, null);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = 'chanserv', string $locale = 'en'): string => $id);

        $cmd = new InfoCommand($channelRepo, $this->createStub(ChanUserAccountPort::class));
        $context = $this->createContext(['#Test'], $notifier, $translator);
        $cmd->execute($context);

        self::assertContains('info.forbidden_status', $rawMessages);
        self::assertNotContains('info.forbidden_reason', $rawMessages);
    }

    #[Test]
    public function showsNoExpireLineWhenNoExpireIsOn(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->changeNoExpire(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertContains('info.no_expire', $rawMessages);
    }

    #[Test]
    public function doesNotShowNoExpireLineWhenNoExpireIsOff(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator));

        self::assertNotContains('info.no_expire', $rawMessages);
    }

    #[Test]
    public function hidesTopicWhenChannelHasSecretMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Secret topic', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+nts', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup));

        self::assertNotContains('info.topic', $rawMessages);
    }

    #[Test]
    public function hidesTopicWhenChannelHasPrivateMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Private topic', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+ntp', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup));

        self::assertNotContains('info.topic', $rawMessages);
    }

    #[Test]
    public function showsTopicWhenSenderIsFounderEvenWithSecretMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Secret topic', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+nts', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $senderAccount = new ChanAccountView(1, 'User', 'en');

        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', isIdentified: true);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup, $senderAccount, $sender));

        self::assertContains('info.topic', $rawMessages);
    }

    #[Test]
    public function showsTopicWhenSenderIsOperEvenWithPrivateMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Private topic', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+ntp', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $sender = new SenderView('UID1', 'OperNick', 'i', 'h', 'c', 'ip', isIdentified: false, isOper: true);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup, null, $sender));

        self::assertContains('info.topic', $rawMessages);
    }

    #[Test]
    public function hidesTopicWhenSenderIdentifiedButNotFounderWithPrivateMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Private topic', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+ntp', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        // Sender is identified but NOT founder and NOT oper — hits the final return false in isSenderFounderOrOper
        $senderAccount = new ChanAccountView(999, 'User', 'en');
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', isIdentified: true);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup, $senderAccount, $sender));

        self::assertNotContains('info.topic', $rawMessages);
    }

    #[Test]
    public function hidesTopicWhenSenderNullAndChannelHasSecretMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Secret topic', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+nts', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);

        $context = new ChanServContext(
            null,
            null,
            'INFO',
            ['#Test'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $cmd->execute($context);

        self::assertNotContains('info.topic', $rawMessages);
    }

    #[Test]
    public function showsTopicWhenChannelHasNoSecretOrPrivateMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Public topic', 'TopicSetter');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+nt', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup));

        self::assertContains('info.topic', $rawMessages);
    }

    #[Test]
    public function hidesTopicWhenMlockContainsSecretMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Secret topic via MLOCK', 'TopicSetter');
        $channel->configureMlock(true, '+nts');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+nt', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup));

        self::assertNotContains('info.topic', $rawMessages);
    }

    #[Test]
    public function hidesTopicWhenMlockContainsPrivateMode(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Private topic via MLOCK', 'TopicSetter');
        $channel->configureMlock(true, '+ntp');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+nt', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup));

        self::assertNotContains('info.topic', $rawMessages);
    }

    #[Test]
    public function showsTopicWhenMlockHasNoSecretOrPrivate(): void
    {
        $channel = RegisteredChannel::register('#Test', 1, 'Desc');
        $channel->updateTopic('Public topic with MLOCK', 'TopicSetter');
        $channel->configureMlock(true, '+nt');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $founder = new ChanAccountView(1, 'FounderNick', 'en');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView('#Test', '+nt', null, 1));

        $rawMessages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$rawMessages): void {
            $rawMessages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($channelRepo, $nickRepo);
        $cmd->execute($this->createContext(['#Test'], $notifier, $translator, $channelLookup));

        self::assertContains('info.topic', $rawMessages);
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
