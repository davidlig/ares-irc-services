<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\ForbidCommand;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleResult;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycle;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ForbidCommand::class)]
#[UsesClass(ManageChannelLifecycleHandler::class)]
#[UsesClass(ManageChannelLifecycle::class)]
#[UsesClass(ChannelLifecycleResult::class)]
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

        self::assertSame(79, $cmd->getOrder());
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
    public function allowsForbiddenChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('forbid');

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

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

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

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

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

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

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', '  '], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.reason_required', $messages);
    }

    #[Test]
    public function executeForbidsNewChannelSuccessfully(): void
    {
        $sender = $this->createSender();
        $forbiddenChannel = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#test', 'abuse');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($forbiddenChannel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('#test', 'abuse', 'OperUser', self::isInstanceOf(DateTimeImmutable::class))->willReturn($forbiddenChannel);

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'abuse'], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.success', $messages);
    }

    #[Test]
    public function executeUpdatesReasonOfAlreadyForbiddenChannel(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#test', 'old reason');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($channel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('#test', 'new reason', 'OperUser', self::isInstanceOf(DateTimeImmutable::class))->willReturn($channel);

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'new', 'reason'], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.updated', $messages);
    }

    #[Test]
    public function executeForbidsExistingNonForbiddenChannel(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test channel');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($channel, 1);

        $forbiddenChannel = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#test', 'abuse');
        $ref2 = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref2->setValue($forbiddenChannel, 2);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('#test', 'abuse', 'OperUser', self::isInstanceOf(DateTimeImmutable::class))->willReturn($forbiddenChannel);

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'abuse'], $messages);

        $cmd->execute($context);

        self::assertContains('forbid.success', $messages);
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulForbid(): void
    {
        $sender = $this->createSender();
        $forbiddenChannel = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#test', 'abuse');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($forbiddenChannel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $forbiddenService = $this->createStub(ChannelForbiddenService::class);
        $forbiddenService->method('forbid')->willReturn($forbiddenChannel);

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'abuse'], $messages);

        $outcome = $cmd->execute($context);

        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame('abuse', $auditData->reason);
    }

    #[Test]
    public function getAuditDataReturnsDataAfterUpdatedForbid(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#test', 'old reason');
        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($channel, 1);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $forbiddenService = $this->createStub(ChannelForbiddenService::class);
        $forbiddenService->method('forbid')->willReturn($channel);

        $cmd = $this->createCommandWith($channelRepository, $forbiddenService);

        $messages = [];
        $context = $this->createContext($sender, ['#test', 'new', 'reason'], $messages);

        $outcome = $cmd->execute($context);

        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame('new reason', $auditData->reason);
    }

    #[Test]
    public function getAuditDataReturnsNullAfterInvalidChannel(): void
    {
        $sender = $this->createSender();

        $cmd = $this->createCommandWith(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelForbiddenService::class),
        );

        $messages = [];
        $context = $this->createContext($sender, ['notachannel', 'abuse'], $messages);

        $outcome = $cmd->execute($context);

        self::assertFalse($outcome->success);
    }

    #[Test]
    public function getAuditDataReturnsNullAfterEmptyReason(): void
    {
        $sender = $this->createSender();

        $cmd = $this->createCommandWith(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelForbiddenService::class),
        );

        $messages = [];
        $context = $this->createContext($sender, ['#test', ''], $messages);

        $outcome = $cmd->execute($context);

        self::assertFalse($outcome->success);
    }

    private function createCommand(): ForbidCommand
    {
        return $this->createCommandWith(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelForbiddenService::class),
        );
    }

    private function createCommandWith(
        RegisteredChannelRepositoryInterface $channels,
        ChannelForbiddenService $forbiddenService,
    ): ForbidCommand {
        return new ForbidCommand(new ManageChannelLifecycleHandler(
            $channels,
            $this->createStub(ChanDropService::class),
            $forbiddenService,
            $this->createStub(ChannelSuspensionService::class),
            $this->createStub(EventBusInterface::class),
        ));
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o');
    }

    /**
     * @param array<string> $args
     * @param array<string> $messages
     */
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
