<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\RenameCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickForceService;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RenameCommand::class)]
final class RenameCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsRename(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('RENAME', $cmd->getName());
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

        self::assertSame('rename.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('rename.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSixtyFive(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(65, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('rename.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsRenamePermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::RENAME, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function getHelpParamsReturnsGuestPrefix(): void
    {
        $cmd = new RenameCommand(
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(LoggerInterface::class),
            'CustomPrefix-',
        );

        self::assertSame(['%prefix%' => 'CustomPrefix-'], $cmd->getHelpParams());
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();

        self::assertNull($cmd->getAuditData($this->createStub(NickServContext::class)));
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $messages = [];

        $context = $this->createContext(null, ['TestNick'], $messages);

        $cmd = new RenameCommand(
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertEmpty($messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithNonexistentNickRepliesNotOnline(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $context = $this->createContext($sender, ['UnknownNick'], $messages, userLookup: $userLookup);

        $cmd = new RenameCommand(
            $userLookup,
            $this->createStub(NickForceService::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('rename.not_online', $messages);
    }

    #[Test]
    public function executeWithRootNickRepliesCannotRenameRoot(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $targetUser = new SenderView('UID2', 'RootUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($targetUser);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::root('RootUser'));

        $context = $this->createContext($sender, ['RootUser'], $messages, userLookup: $userLookup);

        $cmd = new RenameCommand(
            $userLookup,
            $this->createStub(NickForceService::class),
            $validator,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('rename.cannot_rename_root', $messages);
    }

    #[Test]
    public function executeWithIrcopNickRepliesCannotRenameOper(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $targetUser = new SenderView('UID2', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($targetUser);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::ircop('OperUser'));

        $context = $this->createContext($sender, ['OperUser'], $messages, userLookup: $userLookup);

        $cmd = new RenameCommand(
            $userLookup,
            $this->createStub(NickForceService::class),
            $validator,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('rename.cannot_rename_oper', $messages);
    }

    #[Test]
    public function executeWithServiceNickRepliesCannotRenameService(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $targetUser = new SenderView('UID2', 'NickServ', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($targetUser);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::service('NickServ'));

        $context = $this->createContext($sender, ['NickServ'], $messages, userLookup: $userLookup);

        $cmd = new RenameCommand(
            $userLookup,
            $this->createStub(NickForceService::class),
            $validator,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('rename.cannot_rename_service', $messages);
    }

    #[Test]
    public function executeWithAllowedUserCallsForceService(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $targetUser = new SenderView('UID2', 'BadUser', 'ident', 'host.example.com', 'c', 'aBcD', false, false, 'SID1', 'host.example.com', 'i', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($targetUser);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('BadUser', null));

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID2', null, 'ircop-rename');

        $context = $this->createContext($sender, ['BadUser'], $messages, userLookup: $userLookup);

        $cmd = new RenameCommand(
            $userLookup,
            $forceService,
            $validator,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('rename.success', $messages);
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulExecute(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $targetUser = new SenderView('UID2', 'BadUser', 'ident', 'host.example.com', 'c', 'aBcD', false, false, 'SID1', 'host.example.com', 'i', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($targetUser);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('BadUser', null));

        $context = $this->createContext($sender, ['BadUser'], $messages, userLookup: $userLookup);

        $cmd = new RenameCommand(
            $userLookup,
            $this->createStub(NickForceService::class),
            $validator,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('BadUser', $auditData->target);
        self::assertSame('ident@host.example.com', $auditData->targetHost);
        self::assertSame('aBcD', $auditData->targetIp);
    }

    private function createCommand(): RenameCommand
    {
        return new RenameCommand(
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(NickTargetValidator::class),
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
        ?NetworkUserLookupPort $userLookup = null,
    ): NickServContext {
        $notifierMock = $this->createStub(NickServNotifierInterface::class);
        $notifierMock->method('sendMessage')->willReturnCallback(static function (string $type, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'RENAME',
            $args,
            $notifierMock,
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
