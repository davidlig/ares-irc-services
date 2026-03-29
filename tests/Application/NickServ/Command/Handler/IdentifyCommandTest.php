<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\IdentifyCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\IdentifyFailedAttemptRegistry;
use App\Application\NickServ\NickServClientKeyResolver;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IdentifyCommand::class)]
final class IdentifyCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'IDENTIFY',
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
    public function doesNothingWhenSenderNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(null, ['User', 'pass'], $notifier, $translator));
    }

    #[Test]
    public function replyAlreadyIdentifiedWhenRegistryHasNick(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $identified->register('UID1', 'User');
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'Other', 'i', 'h', 'c', 'ip'), ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.already_identified'], $messages);
    }

    #[Test]
    public function replyAlreadyIdentifiedWhenSenderIsIdentifiedMatchesTarget(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true);
        $cmd->execute($this->createContext($sender, ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.already_identified'], $messages);
        self::assertSame('User', $identified->findNick('UID1'));
    }

    #[Test]
    public function replyLockedOutWhenRemainingLockoutGreaterThanZero(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        // Client key for SenderView(uid, nick, ident, hostname, cloaked, ip) is 'host:h'
        $clientKey = $clientKeyResolver->getClientKey(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'));
        for ($i = 0; $i < 5; ++$i) {
            $failedAttempt->recordFailedAttempt($clientKey, 300);
        }

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'wrong'], $notifier, $translator));

        self::assertSame(['identify.locked_out'], $messages);
    }

    #[Test]
    public function replyNotRegisteredWhenAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.not_registered'], $messages);
    }

    #[Test]
    public function replyPendingWhenAccountPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.pending'], $messages);
    }

    #[Test]
    public function replySuspendedWhenAccountSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(true);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getReason')->willReturn('Policy violation');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.suspended'], $messages);
    }

    #[Test]
    public function replyForbiddenWhenAccountForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.forbidden'], $messages);
    }

    #[Test]
    public function replyInvalidCredentialsWhenPasswordWrong(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('verifyPassword')->willReturn(false);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'wrong'], $notifier, $translator));

        self::assertSame(['identify.invalid_credentials'], $messages);
    }

    #[Test]
    public function successRegistersIdentifiedAndReplies(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('verifyPassword')->willReturn(true);
        $account->method('getNickname')->willReturn('User');
        $account->method('getId')->willReturn(1);
        $account->method('getVhost')->willReturn(null);
        $account->method('getLanguage')->willReturn('en');
        $account->expects(self::once())->method('markSeen');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::anything());

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setUserAccount')->willReturnCallback(static function (): void {});
        $notifier->method('setUserVhost')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'correct'], $notifier, $translator));

        self::assertSame(['identify.success'], $messages);
        self::assertSame('User', $identified->findNick('UID1'));
    }

    #[Test]
    public function successReleasesGhostAndForcesNick(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('verifyPassword')->willReturn(true);
        $account->method('getNickname')->willReturn('User');
        $account->method('getId')->willReturn(1);
        $account->method('getVhost')->willReturn(null);
        $account->method('getLanguage')->willReturn('en');
        $account->expects(self::once())->method('markSeen');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $ghostUser = new SenderView('UID2', 'User', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($ghostUser);

        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::anything());

        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('killUser')->with('UID2', self::callback(static fn (string $v): bool => true));
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setUserAccount')->willReturnCallback(static function (): void {});
        $notifier->method('setUserVhost')->willReturnCallback(static function (): void {});
        $notifier->method('forceNick')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'Guest', 'i', 'h', 'c', 'ip'), ['User', 'correct'], $notifier, $translator));

        self::assertSame(['identify.ghost_released', 'identify.success'], $messages);
    }

    #[Test]
    public function successForcesNickWhenDifferent(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('verifyPassword')->willReturn(true);
        $account->method('getNickname')->willReturn('User');
        $account->method('getId')->willReturn(1);
        $account->method('getVhost')->willReturn(null);
        $account->method('getLanguage')->willReturn('en');
        $account->expects(self::once())->method('markSeen');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $identified = new IdentifiedSessionRegistry();
        $failedAttempt = new IdentifyFailedAttemptRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $vhostResolver = new VhostDisplayResolver('');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::anything());

        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', 'User');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setUserAccount')->willReturnCallback(static function (): void {});
        $notifier->method('setUserVhost')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $userLookup,
            $identified,
            $failedAttempt,
            $clientKeyResolver,
            $vhostResolver,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'Guest', 'i', 'h', 'c', 'ip'), ['User', 'correct'], $notifier, $translator));

        self::assertSame(['identify.success'], $messages);
    }

    #[Test]
    public function getNameReturnsIdentify(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame('IDENTIFY', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsId(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame(['ID'], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame('identify.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame('identify.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsTwo(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame(2, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame('identify.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new IdentifyCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );
        self::assertNull($cmd->getRequiredPermission());
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

    #[Test]
    public function identifyDoesNotApplyVhostWhenIrcopHasForcedVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('verifyPassword')->willReturn(true);
        $account->method('getNickname')->willReturn('Admin');
        $account->method('getId')->willReturn(1);
        $account->method('getVhost')->willReturn('personal.vhost');
        $account->method('getLanguage')->willReturn('en');
        $account->expects(self::once())->method('markSeen');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->with('Admin')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $role = \App\Domain\OperServ\Entity\OperRole::create('ADMIN', 'Admin role', true);
        $role->setForcedVhostPattern('admin.network');
        $ircop = \App\Domain\OperServ\Entity\OperIrcop::create(1, $role);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->with(1)->willReturn($ircop);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserAccount');
        $notifier->expects(self::never())->method('setUserVhost');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $ircopRepo,
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );

        $sender = new SenderView('UID1', 'Admin', 'i', 'h', 'c', 'ip');
        $cmd->execute($this->createContext($sender, ['Admin', 'password'], $notifier, $translator));
    }

    #[Test]
    public function identifyAppliesVhostWhenIrcopHasNoForcedVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('verifyPassword')->willReturn(true);
        $account->method('getNickname')->willReturn('Admin');
        $account->method('getId')->willReturn(1);
        $account->method('getVhost')->willReturn('personal.vhost');
        $account->method('getLanguage')->willReturn('en');
        $account->expects(self::once())->method('markSeen');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->with('Admin')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $role = \App\Domain\OperServ\Entity\OperRole::create('ADMIN', 'Admin role', true);
        $ircop = \App\Domain\OperServ\Entity\OperIrcop::create(1, $role);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->with(1)->willReturn($ircop);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserAccount');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'personal.vhost', 'SID');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $ircopRepo,
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );

        $sender = new SenderView('UID1', 'Admin', 'i', 'h', 'c', 'ip', false, false, 'SID');
        $cmd->execute($this->createContext($sender, ['Admin', 'password'], $notifier, $translator));
    }

    #[Test]
    public function identifyAppliesVhostWhenNotIrcop(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('verifyPassword')->willReturn(true);
        $account->method('getNickname')->willReturn('User');
        $account->method('getId')->willReturn(1);
        $account->method('getVhost')->willReturn('user.vhost');
        $account->method('getLanguage')->willReturn('en');
        $account->expects(self::once())->method('markSeen');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->with('User')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->with(1)->willReturn(null);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserAccount');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'user.vhost', 'SID');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new IdentifyCommand(
            $nickRepo,
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            new IdentifyFailedAttemptRegistry(),
            new NickServClientKeyResolver(),
            new VhostDisplayResolver(''),
            $ircopRepo,
            $this->createStub(EventDispatcherInterface::class),
            5,
            300,
            900,
        );

        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, false, 'SID');
        $cmd->execute($this->createContext($sender, ['User', 'password'], $notifier, $translator));
    }
}
