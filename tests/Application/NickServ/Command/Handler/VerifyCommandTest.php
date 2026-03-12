<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Command\Handler\VerifyCommand;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(VerifyCommand::class)]
final class VerifyCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        PendingVerificationRegistry $pendingRegistry,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'VERIFY',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            $pendingRegistry,
            new RecoveryTokenRegistry(),
        );
    }

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $pending = new PendingVerificationRegistry();

        $cmd = new VerifyCommand($this->createStub(RegisteredNickRepositoryInterface::class), new IdentifiedSessionRegistry());
        $cmd->execute($this->createContext(null, ['token'], $notifier, $translator, $pending));
    }

    #[Test]
    public function replyNoPendingWhenAccountMissingOrNotPending(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new VerifyCommand($nickRepo, new IdentifiedSessionRegistry());
        $cmd->execute($this->createContext($sender, ['any-token'], $notifier, $translator, new PendingVerificationRegistry()));

        self::assertSame(['verify.no_pending'], $messages);
    }

    #[Test]
    public function replyInvalidTokenWhenTokenNotConsumed(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getNickname')->willReturn('Nick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $pending = new PendingVerificationRegistry();
        // no store for Nick → consume returns false

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new VerifyCommand($nickRepo, new IdentifiedSessionRegistry());
        $cmd->execute($this->createContext($sender, ['wrong-token'], $notifier, $translator, $pending));

        self::assertSame(['verify.invalid_token'], $messages);
    }

    #[Test]
    public function successActivatesAccountAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip');
        $expires = (new \DateTimeImmutable())->modify('+1 hour');
        $pending = new PendingVerificationRegistry();
        $pending->store('Nick', 'valid-token', $expires);

        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getNickname')->willReturn('Nick');
        $account->expects(self::once())->method('activate');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserAccount')->with('UID1', 'Nick');
        $messages = [];
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new VerifyCommand($nickRepo, new IdentifiedSessionRegistry());
        $cmd->execute($this->createContext($sender, ['valid-token'], $notifier, $translator, $pending));

        self::assertSame(['verify.success'], $messages);
    }
}
