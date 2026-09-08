<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\InfoCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\AssociatedChannel;
use App\NickServ\Application\UseCase\Info\InfoNick;
use App\NickServ\Application\UseCase\Info\InfoNickHandlerInterface;
use App\NickServ\Application\UseCase\Info\InfoNickResult;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfoCommand::class)]
final class InfoCommandTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 18:00:00 UTC');
    }

    #[Test]
    public function exposesInfoMetadata(): void
    {
        $command = new InfoCommand(
            $this->createStub(InfoNickHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        self::assertSame('INFO', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('info.syntax', $command->getSyntaxKey());
        self::assertSame('info.help', $command->getHelpKey());
        self::assertSame(5, $command->getOrder());
        self::assertSame('info.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function mapsArgumentsAndContextProperly(): void
    {
        $sender = new SenderView('UID1', 'Sender', 'ident', 'host', 'cloak', 'ip', isIdentified: true, isOper: true);
        $targetUser = new SenderView('UID2', 'Target', 'ident2', 'host2', 'cloak2', 'ip2', isIdentified: true);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('Target')->willReturn($targetUser);

        $handler = $this->createMock(InfoNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (InfoNick $input): bool => 'Target' === $input->targetNick
                && 'Sender' === $input->senderNick
                && true === $input->senderIsIdentified
                && true === $input->senderIsOper
                && true === $input->targetIsOnlineAndIdentified))
            ->willReturn(InfoNickResult::notRegistered('Target'));

        $command = new InfoCommand($handler, $userLookup);
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Target']));

        self::assertSame(['info.not_registered'], $messages);
    }

    #[Test]
    public function handlesNullSenderAndOfflineTarget(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('Target')->willReturn(null);

        $handler = $this->createMock(InfoNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (InfoNick $input): bool => 'Target' === $input->targetNick
                && null === $input->senderNick
                && false === $input->senderIsIdentified
                && false === $input->senderIsOper
                && false === $input->targetIsOnlineAndIdentified))
            ->willReturn(InfoNickResult::notRegistered('Target'));

        $command = new InfoCommand($handler, $userLookup);
        $messages = [];
        $command->execute($this->createContext(null, $messages, ['Target']));

        self::assertSame(['info.not_registered'], $messages);
    }

    #[Test]
    public function presentsForbiddenOutcomeWithAndWithoutReason(): void
    {
        // With reason
        $handler1 = $this->createStub(InfoNickHandlerInterface::class);
        $handler1->method('handle')->willReturn(InfoNickResult::forbidden('bad', 'Rule broken'));
        $messages = [];
        new InfoCommand($handler1, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages, ['bad']));
        self::assertSame(['info.header', 'info.status_forbidden', 'info.status', 'info.reason', 'info.footer'], $messages);

        // Without reason
        $handler2 = $this->createStub(InfoNickHandlerInterface::class);
        $handler2->method('handle')->willReturn(InfoNickResult::forbidden('bad', null));
        $messages = [];
        new InfoCommand($handler2, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages, ['bad']));
        self::assertSame(['info.header', 'info.status_forbidden', 'info.status', 'info.footer'], $messages);
    }

    #[Test]
    public function presentsPendingDeletionWithAndWithoutDates(): void
    {
        // With dates
        $handler1 = $this->createStub(InfoNickHandlerInterface::class);
        $handler1->method('handle')->willReturn(InfoNickResult::pendingDeletion('retiring', $this->now, $this->now->modify('+7 days')));
        $messages = [];
        new InfoCommand($handler1, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages, ['retiring']));
        self::assertSame([
            'info.header',
            'info.status_pending_deletion',
            'info.status',
            'info.pending_deletion_at',
            'info.pending_deletion_until',
            'info.pending_deletion_notice',
            'info.footer',
        ], $messages);

        // Without dates
        $handler2 = $this->createStub(InfoNickHandlerInterface::class);
        $handler2->method('handle')->willReturn(InfoNickResult::pendingDeletion('retiring', null, null));
        $messages = [];
        new InfoCommand($handler2, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages, ['retiring']));
        self::assertSame([
            'info.header',
            'info.status_pending_deletion',
            'info.status',
            'info.pending_deletion_notice',
            'info.footer',
        ], $messages);
    }

    #[Test]
    public function presentsPrivateOutcome(): void
    {
        $handler = $this->createStub(InfoNickHandlerInterface::class);
        $handler->method('handle')->willReturn(InfoNickResult::private('secret'));
        $messages = [];
        new InfoCommand($handler, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages, ['secret']));
        self::assertSame(['info.private'], $messages);
    }

    #[Test]
    public function presentsVisibleOutcomeFullDetails(): void
    {
        $channelAccess = new AssociatedChannel('#access', 'access', 100);
        $channelFounder = new AssociatedChannel('#founder', 'founder', null);
        $channelSuccessor = new AssociatedChannel('#successor', 'successor', null);

        $result = InfoNickResult::visible(
            nickname: 'Alice',
            status: NickStatus::Suspended,
            suspendedReason: 'Spamming',
            suspendedUntil: $this->now->modify('+1 day'),
            registeredAt: $this->now->modify('-10 days'),
            lastSeenOnline: false,
            lastSeenAt: $this->now->modify('-1 hour'),
            lastQuitMessage: 'Quit: Ping timeout',
            lastConnectIp: '1.2.3.4',
            lastConnectHost: 'isp.host',
            language: 'es',
            email: 'alice@example.com',
            displayVhost: 'custom.vhost',
            isNoExpire: true,
            isOwnerIdentified: true,
            channels: [$channelAccess, $channelFounder, $channelSuccessor],
        );

        $handler = $this->createStub(InfoNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);
        $messages = [];
        new InfoCommand($handler, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages, ['Alice']));

        self::assertSame([
            'info.header',
            'info.status_suspended',
            'info.status',
            'info.reason',
            'info.suspended_until',
            'info.registered_at',
            'info.last_seen_at',
            'info.last_quit',
            'info.last_connect',
            'info.language',
            'info.email',
            'info.vhost',
            'info.no_expire',
            'info.channels_header',
            'info.channels_entry_access',
            'info.channels_entry_founder',
            'info.channels_entry_successor',
            'info.footer',
        ], $messages);
    }

    #[Test]
    public function presentsVisibleOutcomeAlternativeBranches(): void
    {
        // Suspended permanent, last seen online, null quit/connect/vhost
        $result = InfoNickResult::visible(
            nickname: 'Bob',
            status: NickStatus::Suspended,
            suspendedReason: null,
            suspendedUntil: null, // permanent
            registeredAt: $this->now,
            lastSeenOnline: true,
            lastSeenAt: null,
            lastQuitMessage: null,
            lastConnectIp: null,
            lastConnectHost: null,
            language: 'en',
            email: null,
            displayVhost: '',
            isNoExpire: false,
            isOwnerIdentified: false,
            channels: [],
        );

        $handler = $this->createStub(InfoNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);
        $messages = [];
        new InfoCommand($handler, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages, ['Bob']));

        self::assertSame([
            'info.header',
            'info.status_suspended',
            'info.status',
            'info.suspended_permanent',
            'info.registered_at',
            'info.last_seen_online',
            'info.language',
            'info.footer',
        ], $messages);

        // Registered status, last seen never, single connect IP
        $result2 = InfoNickResult::visible(
            nickname: 'Charlie',
            status: NickStatus::Registered,
            suspendedReason: null,
            suspendedUntil: null,
            registeredAt: $this->now,
            lastSeenOnline: false,
            lastSeenAt: null, // last_seen_never
            lastQuitMessage: null,
            lastConnectIp: '10.0.0.1',
            lastConnectHost: null,
            language: 'en',
            email: null,
            displayVhost: '',
            isNoExpire: false,
            isOwnerIdentified: false,
            channels: [],
        );

        $handler2 = $this->createStub(InfoNickHandlerInterface::class);
        $handler2->method('handle')->willReturn($result2);
        $messages2 = [];
        new InfoCommand($handler2, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext(null, $messages2, ['Charlie']));

        self::assertSame([
            'info.header',
            'info.status_registered',
            'info.status',
            'info.registered_at',
            'info.last_seen_never',
            'info.last_connect',
            'info.language',
            'info.footer',
        ], $messages2);
    }

    /**
     * @param list<mixed>  $messages
     * @param list<string> $args
     */
    private function createContext(
        ?SenderView $sender,
        array &$messages,
        array $args,
    ): NickServContext {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages): string {
            $messages[] = $key;

            return $key;
        });

        return new NickServContext(
            $sender,
            null,
            'INFO',
            $args,
            $this->createStub(NickServNotifierInterface::class),
            $translator,
            'es',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([$this->nicknameProvider()]),
        );
    }

    private function nicknameProvider(): ServiceNicknameProviderInterface
    {
        return new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
    }
}
