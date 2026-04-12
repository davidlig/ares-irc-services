<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Service;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChannelSuspensionService::class)]
final class ChannelSuspensionServiceTest extends TestCase
{
    private ChannelLookupPort $channelLookup;

    private ActiveChannelModeSupportProviderInterface $modeSupportProvider;

    private ChannelModeSupportInterface $modeSupport;

    protected function setUp(): void
    {
        $this->channelLookup = $this->createStub(ChannelLookupPort::class);
        $this->modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($this->modeSupport);
    }

    private function createService(
        ?ChannelServiceActionsPort $channelServiceActions = null,
        ?ChanServNotifierInterface $notifier = null,
        ?TranslatorInterface $translator = null,
    ): ChannelSuspensionService {
        return new ChannelSuspensionService(
            $channelServiceActions ?? $this->createStub(ChannelServiceActionsPort::class),
            $notifier ?? $this->createStub(ChanServNotifierInterface::class),
            $this->channelLookup,
            $this->modeSupportProvider,
            $translator ?? $this->createStubTranslator(),
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

    #[Test]
    public function enforceSuspensionRemovesRegisteredAndPermanentModesAndSendsNotice(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $view = new ChannelView('#test', '+rPnt', 'Topic', 5);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-rP', []);

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendNoticeToChannel')
            ->with('#test', 'suspend.notice_channel');

        $this->createService($channelServiceActions, $notifier)->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionRemovesOnlyRegisteredModeWhenPermanentNotSupported(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Spam');
        $view = new ChannelView('#test', '+rnt', 'Topic', 3);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-r', []);

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendNoticeToChannel');

        $this->createService($channelServiceActions, $notifier)->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionSendsNoticeToChannelWithReason(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuse of services');
        $view = new ChannelView('#test', '+nt', 'Topic', 5);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(false);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendNoticeToChannel')
            ->with('#test', 'suspend.notice_channel');

        $this->createService(notifier: $notifier)->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionSendsNoticeEvenWhenChannelNotOnNetwork(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuse');

        $this->channelLookup->method('findByChannelName')->willReturn(null);

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendNoticeToChannel')
            ->with('#test', 'suspend.notice_channel');

        $this->createService(notifier: $notifier)->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionDoesNotKickUsers(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $members = [
            ['uid' => 'UIDAAA', 'roleLetter' => 'o'],
            ['uid' => 'UIDAAB', 'roleLetter' => 'v'],
        ];
        $view = new ChannelView('#test', '+nt', 'Topic', 2, $members);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(false);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())
            ->method('kickFromChannel');

        $this->createService($channelServiceActions)->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionTranslatesWithEnglishLocale(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuse');

        $this->channelLookup->method('findByChannelName')->willReturn(null);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(false);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

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
                    'params' => $params,
                    'domain' => $domain,
                    'locale' => $locale,
                ];

                return $id;
            });

        $service = new ChannelSuspensionService(
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChanServNotifierInterface::class),
            $this->channelLookup,
            $this->modeSupportProvider,
            $translator,
            'en',
        );
        $service->enforceSuspension($channel);

        self::assertSame('suspend.notice_channel', $translatedArgs['id']);
        self::assertSame('Abuse', $translatedArgs['params']['%reason%']);
        self::assertSame('chanserv', $translatedArgs['domain']);
        self::assertSame('en', $translatedArgs['locale']);
    }

    #[Test]
    public function enforceSuspensionTranslatesWithConfiguredDefaultLanguage(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuso');

        $this->channelLookup->method('findByChannelName')->willReturn(null);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(false);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

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
                    'params' => $params,
                    'domain' => $domain,
                    'locale' => $locale,
                ];

                return $id;
            });

        $service = new ChannelSuspensionService(
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChanServNotifierInterface::class),
            $this->channelLookup,
            $this->modeSupportProvider,
            $translator,
            'es',
        );
        $service->enforceSuspension($channel);

        self::assertSame('es', $translatedArgs['locale']);
    }

    #[Test]
    public function enforceSuspensionPassesEmptyStringForEmptyReason(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('');

        $this->channelLookup->method('findByChannelName')->willReturn(null);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(false);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

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
                    'params' => $params,
                    'domain' => $domain,
                    'locale' => $locale,
                ];

                return $id;
            });

        $this->createService(translator: $translator)->enforceSuspension($channel);

        self::assertSame('', $translatedArgs['params']['%reason%']);
    }

    #[Test]
    public function enforceSuspensionSendsNoticeEvenWhenNoModesToRemove(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuse');
        $view = new ChannelView('#test', '+nt', 'Topic', 2);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendNoticeToChannel');

        $this->createService(notifier: $notifier)->enforceSuspension($channel);
    }

    #[Test]
    public function liftSuspensionRestoresRegisteredAndPermanentModes(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $view = new ChannelView('#test', '+nt', 'Topic', 5);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+rP', []);

        $this->createService($channelServiceActions)->liftSuspension($channel);
    }

    #[Test]
    public function liftSuspensionDoesNothingWhenChannelNotOnNetwork(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');

        $this->channelLookup->method('findByChannelName')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->createService($channelServiceActions)->liftSuspension($channel);
    }

    #[Test]
    public function liftSuspensionDoesNothingWhenModesAlreadyPresent(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $view = new ChannelView('#test', '+rPnt', 'Topic', 5);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->createService($channelServiceActions)->liftSuspension($channel);
    }
}
