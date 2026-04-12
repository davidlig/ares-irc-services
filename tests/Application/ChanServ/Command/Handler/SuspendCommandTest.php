<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\SuspendCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelSuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SuspendCommand::class)]
final class SuspendCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsSuspend(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('SUSPEND', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsThree(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(3, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('suspend.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('suspend.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsExpected(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(77, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('suspend.short', $cmd->getShortDescKey());
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
    public function getRequiredPermissionReturnsSuspendPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(ChanServPermission::SUSPEND, $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function executeWithInvalidChannelRepliesInvalidChannel(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $context = $this->createContext($sender, ['invalidchan', '7d', 'abuse'], $messages);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeWithEmptyReasonRepliesSyntaxError(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $context = $this->createContext($sender, ['#test', '7d', ''], $messages);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeWithNonRegisteredChannelRepliesNotRegistered(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $context = $this->createContext($sender, ['#test', '7d', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $this->createStub(ChannelSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.not_registered', $messages);
    }

    #[Test]
    public function executeWithAlreadySuspendedChannelRepliesAlreadySuspended(): void
    {
        $sender = $this->createSender();
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');
        $channel->suspend('previous reason');
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $context = $this->createContext($sender, ['#test', '7d', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $this->createStub(ChannelSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.already_suspended', $messages);
    }

    #[Test]
    public function executeWithInvalidDurationRepliesInvalidDuration(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $context = $this->createContext($sender, ['#test', 'abc', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $this->createStub(ChannelSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.invalid_duration', $messages);
    }

    #[Test]
    public function executeWithPermanentSuspensionSucceeds(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension')->with($channel);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $context = $this->createContext($sender, ['#test', '0', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $eventDispatcher,
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($channel->isSuspended());
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(ChannelSuspendedEvent::class, $dispatchedEvents[0]);
        self::assertSame(1, $dispatchedEvents[0]->channelId);
        self::assertSame('#test', $dispatchedEvents[0]->channelName);
        self::assertSame('abuse', $dispatchedEvents[0]->reason);
        self::assertNull($dispatchedEvents[0]->duration);
        self::assertNull($dispatchedEvents[0]->expiresAt);
    }

    #[Test]
    public function executeWithDurationSuspensionSucceeds(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $context = $this->createContext($sender, ['#test', '7d', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($channel->isSuspended());
        self::assertNotNull($channel->getSuspendedUntil());
    }

    #[Test]
    public function executeWithNullSenderDoesNothing(): void
    {
        $messages = [];

        $context = $this->createContext(null, ['#test', '7d', 'abuse'], $messages);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();
        $messages = [];

        $context = $this->createContext($this->createSender(), ['#test', '0', 'abuse'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsDataAfterExecute(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $context = $this->createContext($sender, ['#test', '0', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame('abuse', $auditData->reason);
        self::assertSame(['duration' => '0'], $auditData->extra);
    }

    private function createCommand(): SuspendCommand
    {
        return new SuspendCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    }

    private function createChannelWithId(string $channelName, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($channelName, 1, 'Test channel');

        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, $id);

        return $channel;
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        array &$messages,
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
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
            'SUSPEND',
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

    #[Test]
    public function executeWithHourDurationParsesHours(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $context = $this->createContext($sender, ['#test', '5h', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($channel->isSuspended());
        self::assertNotNull($channel->getSuspendedUntil());
    }

    #[Test]
    public function executeWithMinuteDurationParsesMinutes(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $context = $this->createContext($sender, ['#test', '30m', 'spam'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($channel->isSuspended());
        self::assertNotNull($channel->getSuspendedUntil());
    }

    #[Test]
    public function executeWithEmptyIpBase64DecodesAsAsterisk(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', '', false, true, 'SID1', 'h', 'o', '');
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $context = $this->createContext($sender, ['#test', '0', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $eventDispatcher,
        );
        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(ChannelSuspendedEvent::class, $dispatchedEvents[0]);
        self::assertSame('*', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function executeWithAsteriskIpBase64DecodesAsAsterisk(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', '*', false, true, 'SID1', 'h', 'o', '');
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $context = $this->createContext($sender, ['#test', '0', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $eventDispatcher,
        );
        $cmd->execute($context);

        self::assertCount(1, $dispatchedEvents);
        self::assertSame('*', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function executeWithInvalidBase64IpFallsBackToRawString(): void
    {
        $invalidBase64 = '!!!invalid!!!';
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', $invalidBase64, false, true, 'SID1', 'h', 'o', '');
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())->method('save');

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $context = $this->createContext($sender, ['#test', '0', 'abuse'], $messages, channelRepository: $channelRepository);

        $cmd = new SuspendCommand(
            $channelRepository,
            $suspensionService,
            $eventDispatcher,
        );
        $cmd->execute($context);

        self::assertCount(1, $dispatchedEvents);
        self::assertSame($invalidBase64, $dispatchedEvents[0]->performedByIp);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('chanserv');
        $provider->method('getNickname')->willReturn('ChanServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
