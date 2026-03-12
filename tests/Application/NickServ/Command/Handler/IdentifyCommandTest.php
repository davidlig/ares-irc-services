<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Command\Handler\IdentifyCommand;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\IdentifyFailedAttemptRegistry;
use App\Application\NickServ\NickServClientKeyResolver;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
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
            new \App\Application\NickServ\PendingVerificationRegistry(),
            new \App\Application\NickServ\RecoveryTokenRegistry(),
        );
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
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
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
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'Other', 'i', 'h', 'c', 'ip'), ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.already_identified'], $messages);
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
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
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
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
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
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
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
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'pass'], $notifier, $translator));

        self::assertSame(['identify.pending'], $messages);
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
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
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
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
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
            $eventDispatcher,
            5,
            300,
            900,
        );
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'correct'], $notifier, $translator));

        self::assertSame(['identify.success'], $messages);
        self::assertSame('User', $identified->findNick('UID1'));
    }
}
