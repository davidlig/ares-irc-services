<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\SetEmailHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
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
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('TestNick');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            $eventDispatcher,
        );

        $messages = [];
        $context = $this->createContext($messages);

        $handler->handle($context, $account, 'new@example.com', true);

        self::assertContains('set.email.success', $messages);
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
        $nickRepo->method('findByEmail')->willReturn($existingAccount);

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $messages = [];
        $context = $this->createContext($messages);

        $handler->handle($context, $account, 'duplicate@example.com', true);

        self::assertContains('register.email_already_used', $messages);
    }

    private function createContext(array &$messages): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $type, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'AQ==', false, true, 'SID1', 'h', 'o', ''),
            null,
            'SET',
            ['EMAIL', 'new@example.com'],
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

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
