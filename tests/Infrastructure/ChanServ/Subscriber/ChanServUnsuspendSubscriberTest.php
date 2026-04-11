<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\ChanServ\Subscriber\ChanServUnsuspendSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ChanServUnsuspendSubscriber::class)]
final class ChanServUnsuspendSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToChannelUnsuspendedEvent(): void
    {
        self::assertSame(
            [ChannelUnsuspendedEvent::class => ['onChannelUnsuspended', 0]],
            ChanServUnsuspendSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onChannelUnsuspendedCallsLiftSuspensionWhenChannelOnNetwork(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension')->with($channel);

        $view = new ChannelView('#test', '+nt', null, 2);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($view);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: 'Admin',
            performedByNickId: 10,
            performedByIp: '127.0.0.1',
            performedByHost: 'admin@example.com',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedDoesNothingWhenChannelNotFoundInRepo(): void
    {
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::never())->method('liftSuspension');

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 999,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedJoinsChannelWhenNotOnNetworkThenLiftsSuspension(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension')->with($channel);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('joinChannelAsService')->with('#test');

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedAppliesMlockWhenActive(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $channel->configureMlock(true, 'ntl', ['l' => '100']);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $view = new ChannelView('#test', '+', null, 0);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::exactly(2))->method('findByChannelName')->with('#test')->willReturn($view);

        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+ntl', ['100']);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedSkipsMlockWhenNotActive(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(new ChannelView('#test', '+nt', null, 2));

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedJoinsChannelWhenNotOnNetworkAndSkipsMlock(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $channel->configureMlock(true, 'nt');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::exactly(2))->method('findByChannelName')->with('#test')
            ->willReturnOnConsecutiveCalls(null, null);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('joinChannelAsService')->with('#test');
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedDoesNotApplyMlockWhenEmptyMlockString(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $channel->configureMlock(true, '');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $view = new ChannelView('#test', '+', null, 0);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::exactly(2))->method('findByChannelName')->with('#test')->willReturn($view);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedDoesNotApplyMlockWhenAllModesAlreadyPresent(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $channel->configureMlock(true, 'nt');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $view = new ChannelView('#test', '+nt', null, 0);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::exactly(2))->method('findByChannelName')->with('#test')->willReturn($view);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedAppliesMlockWithoutParamsWhenNoMlockParams(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $channel->configureMlock(true, 'nt');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $view = new ChannelView('#test', '+', null, 0);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::exactly(2))->method('findByChannelName')->with('#test')->willReturn($view);

        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+nt', []);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedAppliesOnlyMissingMlockModes(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $channel->configureMlock(true, 'nt');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $view = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::exactly(2))->method('findByChannelName')->with('#test')->willReturn($view);

        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+t', []);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedAppliesMlockWithKeyParam(): void
    {
        $channel = $this->createChannelWithId('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $channel->configureMlock(true, '+ntk', ['k' => 'mykey']);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $suspensionService = $this->createMock(ChannelSuspensionService::class);
        $suspensionService->expects(self::once())->method('liftSuspension');

        $view = new ChannelView('#test', '+', null, 0);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::exactly(2))->method('findByChannelName')->with('#test')->willReturn($view);

        $modeSupport = $this->createMock(ChannelModeSupportInterface::class);
        $modeSupport->expects(self::once())->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);

        $modeSupportProvider = $this->createMock(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->expects(self::once())->method('getSupport')->willReturn($modeSupport);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#test', '+ntk', ['mykey']);

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
        );

        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: '*',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );

        $subscriber->onChannelUnsuspended($event);
    }

    private function createChannelWithId(string $name, int $id, string $description): RegisteredChannel
    {
        $channel = RegisteredChannel::register($name, 1, $description);

        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($channel, $id);

        return $channel;
    }
}
