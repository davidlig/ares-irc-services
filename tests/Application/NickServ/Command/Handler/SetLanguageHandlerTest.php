<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Command\Handler\SetLanguageHandler;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetLanguageHandler::class)]
final class SetLanguageHandlerTest extends TestCase
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
            ['LANGUAGE', $value],
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
    public function emptyValueRepliesSyntaxError(): void
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

        $handler = new SetLanguageHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, ''), $account, '');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function invalidLanguageRepliesInvalid(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('changeLanguage')->willThrowException(new \InvalidArgumentException('Unsupported'));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetLanguageHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'xx'), $account, 'xx');

        self::assertSame(['set.language.invalid'], $messages);
    }

    #[Test]
    public function validLanguageSavesAndRepliesSuccess(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeLanguage')->with('es');
        $account->method('getLanguage')->willReturn('es');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetLanguageHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'es'), $account, 'es');

        self::assertSame(['set.language.success'], $messages);
    }
}
