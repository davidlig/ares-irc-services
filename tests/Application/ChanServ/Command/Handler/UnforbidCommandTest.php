<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\UnforbidCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unforbid.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unforbid.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyNine(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(79, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('unforbid.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
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

        self::assertSame(ChanServPermission::FORBID, $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('unforbid');

        $cmd = new UnforbidCommand($forbiddenService);

        $messages = [];
        $context = $this->createContext(null, ['#test'], $messages);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithInvalidChannelNameRepliesInvalidChannel(): void
    {
        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('unforbid');

        $cmd = new UnforbidCommand($forbiddenService);

        $sender = $this->createSender();
        $messages = [];
        $context = $this->createContext($sender, ['notachannel'], $messages);

        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeUnforbidsSuccessfully(): void
    {
        $sender = $this->createSender();

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('unforbid')->with('#test', 'OperUser')->willReturn(true);

        $cmd = new UnforbidCommand($forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test'], $messages);

        $cmd->execute($context);

        self::assertContains('unforbid.success', $messages);
    }

    #[Test]
    public function executeWithChannelNotForbiddenRepliesNotForbidden(): void
    {
        $sender = $this->createSender();

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('unforbid')->with('#test', 'OperUser')->willReturn(false);

        $cmd = new UnforbidCommand($forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test'], $messages);

        $cmd->execute($context);

        self::assertContains('unforbid.not_forbidden', $messages);
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();
        $messages = [];

        $context = $this->createContext($this->createSender(), ['#test'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulUnforbid(): void
    {
        $sender = $this->createSender();

        $forbiddenService = $this->createStub(ChannelForbiddenService::class);
        $forbiddenService->method('unforbid')->willReturn(true);

        $cmd = new UnforbidCommand($forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test'], $messages);

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertNull($auditData->reason);
    }

    #[Test]
    public function getAuditDataReturnsNullAfterFailedUnforbid(): void
    {
        $sender = $this->createSender();

        $forbiddenService = $this->createStub(ChannelForbiddenService::class);
        $forbiddenService->method('unforbid')->willReturn(false);

        $cmd = new UnforbidCommand($forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test'], $messages);

        $cmd->execute($context);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsNullAfterInvalidChannel(): void
    {
        $sender = $this->createSender();

        $cmd = new UnforbidCommand($this->createStub(ChannelForbiddenService::class));

        $messages = [];
        $context = $this->createContext($sender, ['notachannel'], $messages);

        $cmd->execute($context);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsNullAfterNullSender(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(null, ['#test'], $messages);

        $cmd->execute($context);

        self::assertNull($cmd->getAuditData($context));
    }

    private function createCommand(): UnforbidCommand
    {
        return new UnforbidCommand(
            $this->createStub(ChannelForbiddenService::class),
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
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            $sender,
            null,
            'UNFORBID',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ChannelModeSupportInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('chanserv');
        $provider->method('getNickname')->willReturn('ChanServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
