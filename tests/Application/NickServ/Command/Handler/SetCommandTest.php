<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\SetCommand;
use App\Application\NickServ\Command\Handler\SetEmailHandler;
use App\Application\NickServ\Command\Handler\SetLanguageHandler;
use App\Application\NickServ\Command\Handler\SetMsgHandler;
use App\Application\NickServ\Command\Handler\SetPasswordHandler;
use App\Application\NickServ\Command\Handler\SetPrivateHandler;
use App\Application\NickServ\Command\Handler\SetTimezoneHandler;
use App\Application\NickServ\Command\Handler\SetVhostHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetCommand::class)]
final class SetCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            $sender,
            $senderAccount,
            'SET',
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
    public function doesNothingWhenSenderNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''));
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost);
        $cmd->execute($this->createContext(null, null, ['PASSWORD', 'newpass'], $notifier, $translator));
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['PASSWORD', 'x'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyUnknownOptionWhenOptionNotSupported(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['UNKNOWN', 'value'], $notifier, $translator));

        self::assertSame(['set.unknown_option'], $messages);
    }

    #[Test]
    public function delegatesToLanguageHandlerWhenOptionLanguage(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeLanguage')->with('es');
        $account->method('getLanguage')->willReturn('es');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LANGUAGE', 'es'], $notifier, $translator));

        self::assertSame(['set.language.success'], $messages);
    }
}
