<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\SetEmailHandler;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetEmailHandler::class)]
final class SetEmailHandlerIrcopTest extends TestCase
{
    #[Test]
    public function changeEmailDirectlyChangesEmailWithoutToken(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeEmail')->with('new@example.com');
        $account->method('getEmail')->willReturn('old@example.com');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        $context = $this->createMock(\App\Application\NickServ\Command\NickServContext::class);
        $context->expects(self::once())->method('reply')->with('set.email.success', ['email' => 'new@example.com']);

        $handler->handle($context, $account, 'new@example.com', true);
    }

    #[Test]
    public function changeEmailDirectlyRejectsDuplicateEmail(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getEmail')->willReturn('old@example.com');

        $existingAccount = $this->createStub(RegisteredNick::class);
        $existingAccount->method('getId')->willReturn(2);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByEmail')->with('duplicate@example.com')->willReturn($existingAccount);

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        $context = $this->createMock(\App\Application\NickServ\Command\NickServContext::class);
        $context->expects(self::once())->method('reply')->with('register.email_already_used', ['email' => 'duplicate@example.com']);

        $handler->handle($context, $account, 'duplicate@example.com', true);
    }
}
