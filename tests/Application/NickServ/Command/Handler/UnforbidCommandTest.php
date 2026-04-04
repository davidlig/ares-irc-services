<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\UnforbidCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\ForbiddenNickService;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(UnforbidCommand::class)]
final class UnforbidCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsUnforbid(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('UNFORBID', $cmd->getName());
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

        self::assertSame('unforbid.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unforbid.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyThree(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(73, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unforbid.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsForbidPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::FORBID, $cmd->getRequiredPermission());
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
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::never())->method('unforbid');

        $cmd = new UnforbidCommand(
            $forbiddenService,
            $this->createStub(LoggerInterface::class),
        );

        $messages = [];
        $context = $this->createContext(null, ['TestNick'], $messages);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithNonForbiddenNickRepliesNotForbidden(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('unforbid')->with('TestNick')->willReturn(false);

        $context = $this->createContext($sender, ['TestNick'], $messages);

        $cmd = new UnforbidCommand(
            $forbiddenService,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('unforbid.not_forbidden', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithForbiddenNickRemovesForbid(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('unforbid')->with('BadNick')->willReturn(true);

        $context = $this->createContext($sender, ['BadNick'], $messages);

        $cmd = new UnforbidCommand(
            $forbiddenService,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('unforbid.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('BadNick', $auditData->target);
    }

    private function createCommand(): UnforbidCommand
    {
        return new UnforbidCommand(
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(LoggerInterface::class),
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
            'UNFORBID',
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
