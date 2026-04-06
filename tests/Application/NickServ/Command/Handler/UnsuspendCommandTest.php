<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\UnsuspendCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(UnsuspendCommand::class)]
final class UnsuspendCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsUnsuspend(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('UNSUSPEND', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unsuspend.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unsuspend.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyOne(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(71, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unsuspend.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsSuspendPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::SUSPEND, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();

        self::assertNull($cmd->getAuditData($this->createStub(NickServContext::class)));
    }

    #[Test]
    public function executeWithNonexistentNickRepliesNotRegistered(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $context = $this->createContext($sender, ['UnknownNick'], $messages, nickRepository: $nickRepository);

        $cmd = new UnsuspendCommand($nickRepository, $this->createStub(EventDispatcherInterface::class));

        $cmd->execute($context);

        self::assertContains('unsuspend.not_registered', $messages);
    }

    #[Test]
    public function executeWithNonSuspendedNickRepliesNotSuspended(): void
    {
        $sender = $this->createSender();
        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable());
        $nick->activate();

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['TestNick'], $messages, nickRepository: $nickRepository);

        $cmd = new UnsuspendCommand($nickRepository, $this->createStub(EventDispatcherInterface::class));

        $cmd->execute($context);

        self::assertContains('unsuspend.not_suspended', $messages);
    }

    #[Test]
    public function executeWithSuspendedNickUnsuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->suspend('Spamming', new DateTimeImmutable('+7 days'));

        self::assertTrue($nick->isSuspended());

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $context = $this->createContext($sender, ['TestNick'], $messages, nickRepository: $nickRepository);

        $cmd = new UnsuspendCommand($nickRepository, $eventDispatcher);

        $cmd->execute($context);

        self::assertContains('unsuspend.success', $messages);
        self::assertFalse($nick->isSuspended());
        self::assertNull($nick->getReason());
        self::assertNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function executeWithPermanentSuspendedNickUnsuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->suspend('Permanent ban', null);

        self::assertTrue($nick->isSuspended());

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $context = $this->createContext($sender, ['TestNick'], $messages, nickRepository: $nickRepository);

        $cmd = new UnsuspendCommand($nickRepository, $eventDispatcher);

        $cmd->execute($context);

        self::assertContains('unsuspend.success', $messages);
        self::assertFalse($nick->isSuspended());
        self::assertNull($nick->getReason());
        self::assertNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulExecute(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->suspend('Spamming', new DateTimeImmutable('+7 days'));

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $context = $this->createContext($sender, ['TestNick'], $messages, nickRepository: $nickRepository);

        $cmd = new UnsuspendCommand($nickRepository, $eventDispatcher);

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TestNick', $auditData->target);
        self::assertNull($auditData->reason);
        self::assertSame([], $auditData->extra);
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }

    private function createCommand(): UnsuspendCommand
    {
        return new UnsuspendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        array &$messages,
        ?RegisteredNickRepositoryInterface $nickRepository = null,
    ): NickServContext {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $type, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'UNSUSPEND',
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

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
