<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Command\Handler\SetPrivateHandler;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetPrivateHandler::class)]
final class SetPrivateHandlerTest extends TestCase
{
    private function createContext(
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        string $value,
    ): NickServContext {
        return new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'SET',
            ['PRIVATE', $value],
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
    public function invalidValueRepliesSyntaxError(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetPrivateHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'maybe'), $account, 'maybe');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function onSavesAndRepliesPrivateOn(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('switchPrivate')->with(true);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetPrivateHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'ON'), $account, 'ON');

        self::assertSame(['set.private.on'], $messages);
    }

    #[Test]
    public function offSavesAndRepliesPrivateOff(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('switchPrivate')->with(false);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetPrivateHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'OFF'), $account, 'OFF');

        self::assertSame(['set.private.off'], $messages);
    }
}
