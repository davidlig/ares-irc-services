<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\ChanServ\Subscriber\ChanServUnsuspendSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            'Admin',
            'UNSUSPEND',
            '#test',
            null,
            null,
            'unsuspend.reason_expired',
        );

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
            debugNotifier: $debugNotifier,
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
        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('log');

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
            debugNotifier: $debugNotifier,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
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
    public function onChannelUnsuspendedLogsToDebugWithAsteriskOperatorForMaintenance(): void
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
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            '*',
            'UNSUSPEND',
            '#test',
            null,
            null,
            'unsuspend.reason_expired',
        );

        $subscriber = $this->createSubscriber(
            suspensionService: $suspensionService,
            channelRepo: $channelRepo,
            channelLookup: $channelLookup,
            modeSupportProvider: $modeSupportProvider,
            channelServiceActions: $channelServiceActions,
            debugNotifier: $debugNotifier,
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
    public function onChannelUnsuspendedTranslatesReasonWithDefaultLanguage(): void
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
        $channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);

        $translatedArgs = [];
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->willReturnCallback(static function (
                string $id,
                array $params = [],
                ?string $domain = null,
                ?string $locale = null,
            ) use (&$translatedArgs): string {
                $translatedArgs = [
                    'id' => $id,
                    'domain' => $domain,
                    'locale' => $locale,
                ];

                return $id;
            });

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            '*',
            'UNSUSPEND',
            '#test',
            null,
            null,
            'unsuspend.reason_expired',
        );

        $subscriber = new ChanServUnsuspendSubscriber(
            $suspensionService,
            $channelRepo,
            $channelLookup,
            $modeSupportProvider,
            $channelServiceActions,
            $debugNotifier,
            $translator,
            'es',
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

        self::assertSame('unsuspend.reason_expired', $translatedArgs['id']);
        self::assertSame('chanserv', $translatedArgs['domain']);
        self::assertSame('es', $translatedArgs['locale']);
    }

    private function createSubscriber(
        ?ChannelSuspensionService $suspensionService = null,
        ?RegisteredChannelRepositoryInterface $channelRepo = null,
        ?ChannelLookupPort $channelLookup = null,
        ?ActiveChannelModeSupportProviderInterface $modeSupportProvider = null,
        ?ChannelServiceActionsPort $channelServiceActions = null,
        ?ServiceDebugNotifierInterface $debugNotifier = null,
    ): ChanServUnsuspendSubscriber {
        return new ChanServUnsuspendSubscriber(
            $suspensionService ?? $this->createStub(ChannelSuspensionService::class),
            $channelRepo ?? $this->createStub(RegisteredChannelRepositoryInterface::class),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider ?? $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $channelServiceActions ?? $this->createStub(ChannelServiceActionsPort::class),
            $debugNotifier ?? $this->createStub(ServiceDebugNotifierInterface::class),
            $this->createStubTranslator(),
        );
    }

    private function createStubTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id,
        );

        return $translator;
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
