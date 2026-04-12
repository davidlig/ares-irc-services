<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\ForbidCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ForbidCommand::class)]
final class ForbidCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsForbid(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('FORBID', $cmd->getName());
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
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbid.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbid.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyEight(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(78, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbid.short', $cmd->getShortDescKey());
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
    public function executeWithNullSenderReturnsEarly(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('forbid');

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext(null, ['#test', 'abuse'], $messages);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithInvalidChannelNameRepliesInvalidChannel(): void
    {
        $sender = $this->createSender();
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('forbid');

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['notachannel', 'abuse'], $messages);

        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeWithEmptyReasonRepliesReasonRequired(): void
    {
        $sender = $this->createSender();
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('forbid');

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', ''], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.reason_required', $messages);
    }

    #[Test]
    public function executeWithOnlyWhitespaceReasonRepliesReasonRequired(): void
    {
        $sender = $this->createSender();
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('forbid');

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', '  '], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.reason_required', $messages);
    }

    #[Test]
    public function executeForbidsNewChannelSuccessfully(): void
    {
        $sender = $this->createSender();
        $forbiddenChannel = RegisteredChannel::createForbidden('#test', 'abuse');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($forbiddenChannel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('#test', 'abuse', 'OperUser')->willReturn($forbiddenChannel);

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'abuse'], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.success', $messages);
    }

    #[Test]
    public function executeUpdatesReasonOfAlreadyForbiddenChannel(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::createForbidden('#test', 'old reason');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($channel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('#test', 'new reason', 'OperUser')->willReturn($channel);

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'new', 'reason'], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.updated', $messages);
    }

    #[Test]
    public function executeForbidsExistingNonForbiddenChannel(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($channel, 1);

        $forbiddenChannel = RegisteredChannel::createForbidden('#test', 'abuse');
        $ref2 = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref2->setAccessible(true);
        $ref2->setValue($forbiddenChannel, 2);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('#test', 'abuse', 'OperUser')->willReturn($forbiddenChannel);

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'abuse'], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.success', $messages);
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();
        $messages = [];

        $context = $this->createContext($this->createSender(), ['#test', 'abuse'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulForbid(): void
    {
        $sender = $this->createSender();
        $forbiddenChannel = RegisteredChannel::createForbidden('#test', 'abuse');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($forbiddenChannel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $forbiddenService = $this->createStub(ChannelForbiddenService::class);
        $forbiddenService->method('forbid')->willReturn($forbiddenChannel);

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'abuse'], $messages);

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame('abuse', $auditData->reason);
    }

    #[Test]
    public function getAuditDataReturnsDataAfterUpdatedForbid(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::createForbidden('#test', 'old reason');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($channel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $forbiddenService = $this->createStub(ChannelForbiddenService::class);
        $forbiddenService->method('forbid')->willReturn($channel);

        $cmd = new ForbidCommand($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'new', 'reason'], $messages);

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame('new reason', $auditData->reason);
    }

    #[Test]
    public function getAuditDataReturnsNullAfterInvalidChannel(): void
    {
        $sender = $this->createSender();

        $cmd = new ForbidCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelForbiddenService::class),
        );

        $messages = [];
        $context = $this->createContext($sender, ['notachannel', 'abuse'], $messages);

        $cmd->execute($context);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsNullAfterEmptyReason(): void
    {
        $sender = $this->createSender();

        $cmd = new ForbidCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelForbiddenService::class),
        );

        $messages = [];
        $context = $this->createContext($sender, ['#test', ''], $messages);

        $cmd->execute($context);

        self::assertNull($cmd->getAuditData($context));
    }

    private function createCommand(): ForbidCommand
    {
        return new ForbidCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
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
            'FORBID',
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
