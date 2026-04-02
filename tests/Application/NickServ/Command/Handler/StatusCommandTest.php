<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\StatusCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(StatusCommand::class)]
final class StatusCommandTest extends TestCase
{
    private function createContext(
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'),
            null,
            'STATUS',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function notRegisteredOfflineWhenNoAccountAndUserNotOnline(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $p = []): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['SomeNick'], $notifier, $translator));

        self::assertContains('status.header', $messages);
        self::assertContains('status.not_registered_offline', $messages);
        self::assertContains('status.footer', $messages);
    }

    #[Test]
    public function notRegisteredOnlineWhenNoAccountAndUserOnline(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('U2', 'SomeNick', 'i', 'h', 'c', 'ip'));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['SomeNick'], $notifier, $translator));

        self::assertContains('status.unregistered', $messages);
    }

    #[Test]
    public function pendingShowsPendingAndExpiry(): void
    {
        $expires = (new DateTimeImmutable())->modify('+30 minutes');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Pending);
        $account->method('getExpiresAt')->willReturn($expires);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.pending', $messages);
    }

    #[Test]
    public function registeredIdentifiedWhenOnlineAndIdentified(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('U2', 'Nick', 'i', 'h', 'c', 'ip', true, false, '', ''));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.identified', $messages);
    }

    #[Test]
    public function suspendedShowsReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $account->method('getReason')->willReturn('Abuse');
        $account->method('getSuspendedUntil')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.suspended', $messages);
        self::assertContains('status.suspended_reason', $messages);
        self::assertContains('status.suspended_permanent', $messages);
    }

    #[Test]
    public function replyRegisteredOffline(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.not_connected', $messages);
    }

    #[Test]
    public function replyRegisteredNotIdentified(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('U2', 'Nick', 'i', 'h', 'c', 'ip', false, false, '', ''));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.not_identified', $messages);
    }

    #[Test]
    public function replyForbiddenNoReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Forbidden);
        $account->method('getReason')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.forbidden', $messages);
        self::assertNotContains('status.forbidden_reason', $messages);
    }

    #[Test]
    public function replyPendingNullExpiry(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Pending);
        $account->method('getExpiresAt')->willReturn(null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.pending', $messages);
    }

    #[Test]
    public function replySuspendedNoReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $account->method('getReason')->willReturn(null);
        $account->method('getSuspendedUntil')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.suspended', $messages);
        self::assertNotContains('status.suspended_reason', $messages);
        self::assertContains('status.suspended_permanent', $messages);
    }

    #[Test]
    public function suspendedWithExpiryShowsUntilDate(): void
    {
        $until = (new DateTimeImmutable())->modify('+7 days');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $account->method('getReason')->willReturn('Abuse');
        $account->method('getSuspendedUntil')->willReturn($until);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            if (isset($params['date'])) {
                return $params['date'];
            }

            return $id;
        });

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.suspended', $messages);
        self::assertContains('status.suspended_reason', $messages);
        self::assertContains('status.suspended_until', $messages);
    }

    #[Test]
    public function pendingWithExpiryShowsFormatDate(): void
    {
        $expires = (new DateTimeImmutable())->modify('+30 minutes');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Pending);
        $account->method('getExpiresAt')->willReturn($expires);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            if (isset($params['date'])) {
                return $params['date'];
            }

            return $id;
        });

        $context = new NickServContext(
            new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'),
            null,
            'STATUS',
            ['Nick'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($context);

        self::assertContains('status.pending', $messages);
        self::assertContains('status.pending_expires_at', $messages);
    }

    #[Test]
    public function interfaceMethodsReturnExpectedValues(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $cmd = new StatusCommand($nickRepo, $userLookup);

        self::assertSame('STATUS', $cmd->getName());
        self::assertSame([], $cmd->getAliases());
        self::assertSame(1, $cmd->getMinArgs());
        self::assertSame('status.syntax', $cmd->getSyntaxKey());
        self::assertSame('status.help', $cmd->getHelpKey());
        self::assertSame(5, $cmd->getOrder());
        self::assertSame('status.short', $cmd->getShortDescKey());
        self::assertSame([], $cmd->getSubCommandHelp());
        self::assertFalse($cmd->isOperOnly());
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function replyForbiddenWithReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Forbidden);
        $account->method('getReason')->willReturn('Nickname reserved for network staff');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.forbidden', $messages);
        self::assertContains('status.forbidden_reason', $messages);
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
