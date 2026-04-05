<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\NoexpireCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
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
use Symfony\Contracts\Translation\TranslatorInterface;

use const PASSWORD_BCRYPT;

#[CoversClass(NoexpireCommand::class)]
final class NoexpireCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsNoexpire(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('NOEXPIRE', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('noexpire.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('noexpire.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyTwo(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(72, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('noexpire.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNoexpirePermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::NOEXPIRE, $cmd->getRequiredPermission());
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
        $messages = [];
        $context = $this->createContext(['TestNick', 'ON'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function executeWithInvalidActionRepliesSyntaxError(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext(['TestNick', 'INVALID'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithNotRegisteredNickRepliesNotRegistered(): void
    {
        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $context = $this->createContext(['UnknownNick', 'ON'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertContains('noexpire.not_registered', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithForbiddenNickRepliesForbidden(): void
    {
        $nick = RegisteredNick::createForbidden('ForbiddenNick', 'Banned');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext(['ForbiddenNick', 'ON'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertContains('noexpire.forbidden', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithSuspendedNickRepliesSuspended(): void
    {
        $nick = $this->createNickWithId('SuspendedNick', 1);
        $nick->suspend('Bad behavior');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext(['SuspendedNick', 'ON'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertContains('noexpire.suspended', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithOnSetsNoExpireAndRepliesSuccessOn(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);

        self::assertFalse($nick->isNoExpire(), 'noExpire should be false by default');

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $context = $this->createContext(['TestNick', 'ON'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertTrue($nick->isNoExpire(), 'noExpire should be true after ON');
        self::assertContains('noexpire.success_on', $messages);

        $audit = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $audit);
        self::assertSame('TestNick', $audit->target);
        self::assertSame(['option' => 'ON'], $audit->extra);
    }

    #[Test]
    public function executeWithOffClearsNoExpireAndRepliesSuccessOff(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->setNoExpire(true);

        self::assertTrue($nick->isNoExpire(), 'noExpire should be true before OFF');

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $context = $this->createContext(['TestNick', 'OFF'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertFalse($nick->isNoExpire(), 'noExpire should be false after OFF');
        self::assertContains('noexpire.success_off', $messages);

        $audit = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $audit);
        self::assertSame('TestNick', $audit->target);
        self::assertSame(['option' => 'OFF'], $audit->extra);
    }

    #[Test]
    public function executeWithLowerCaseOnWorks(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $context = $this->createContext(['TestNick', 'on'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertTrue($nick->isNoExpire());
        self::assertContains('noexpire.success_on', $messages);
    }

    #[Test]
    public function executeWithLowerCaseOffWorks(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->setNoExpire(true);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $context = $this->createContext(['TestNick', 'off'], $messages, nickRepository: $nickRepository);

        $cmd = new NoexpireCommand($nickRepository);
        $cmd->execute($context);

        self::assertFalse($nick->isNoExpire());
        self::assertContains('noexpire.success_off', $messages);
    }

    private function createCommand(): NoexpireCommand
    {
        return new NoexpireCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending(
            $nickname,
            password_hash('test', PASSWORD_BCRYPT),
            'test@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($nick, $id);

        return $nick;
    }

    private function createContext(
        array $args,
        array &$messages,
        ?RegisteredNickRepositoryInterface $nickRepository = null,
    ): NickServContext {
        $sender = new SenderView('UID123', 'TestOper', 'test', 'test', 'test', '127.0.0.1', true, true, 'SID001', 'test', 'o', '');

        $notifier = $this->createStub(\App\Application\NickServ\Command\NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $type, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'NOEXPIRE',
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
