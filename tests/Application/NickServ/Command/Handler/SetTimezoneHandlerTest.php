<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\SetTimezoneHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetTimezoneHandler::class)]
final class SetTimezoneHandlerTest extends TestCase
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
            ['TIMEZONE', $value],
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
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetTimezoneHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, ''), $account, '');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function offClearsTimezoneAndRepliesCleared(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeTimezone')->with(null);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetTimezoneHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'OFF'), $account, 'OFF');

        self::assertSame(['set.timezone.cleared'], $messages);
    }

    #[Test]
    public function invalidTimezoneRepliesInvalid(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('changeTimezone')->willThrowException(new InvalidArgumentException('Invalid'));
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetTimezoneHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'Invalid/Zone'), $account, 'Invalid/Zone');

        self::assertSame(['set.timezone.invalid'], $messages);
    }

    #[Test]
    public function validTimezoneSavesAndRepliesSuccess(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeTimezone')->with('Europe/Madrid');
        $account->method('getTimezone')->willReturn('Europe/Madrid');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetTimezoneHandler($nickRepo);
        $handler->handle($this->createContext($notifier, $translator, 'Europe/Madrid'), $account, 'Europe/Madrid');

        self::assertSame(['set.timezone.success'], $messages);
    }
}
