<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\SetEmailHandler;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingEmailChangeRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Port\AsyncMessageDispatcherInterface;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn($existingAccount);

        $handler = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class),
        );

        $messages = [];
        $context = $this->createContext($messages);

        $handler->handle($context, $account, 'duplicate@example.com', true);

        self::assertContains('register.email_already_used', $messages);
    }

    /**
     * @param string[] $messages
     */
    private function createContext(array &$messages): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $type, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'AQ==', false, true, 'SID1', 'h', 'o'),
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
