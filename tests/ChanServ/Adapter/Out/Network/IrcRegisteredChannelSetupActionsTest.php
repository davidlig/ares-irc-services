<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcRegisteredChannelSetupActions;
use App\ChanServ\Application\Port\Out\ChannelModeActions;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeChange;
use App\ChanServ\Domain\ValueObject\ModeChangeAction;
use App\ChanServ\Domain\ValueObject\ModeName;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ServiceUidProviderInterface;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(IrcRegisteredChannelSetupActions::class)]
final class IrcRegisteredChannelSetupActionsTest extends TestCase
{
    #[Test]
    public function restoresSupportedRegistrationAndPermanentModes(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::once())->method('setChannelModes')->with('#opers', '+rP');
        $support = $this->support();
        $support->method('getChannelRegisteredModeLetter')->willReturn('r');
        $support->method('getPermanentChannelModeLetter')->willReturn('P');

        $this->actions($network, support: $support)->restoreRegistrationModes('#opers');
    }

    #[Test]
    public function skipsRegistrationModesWhenTheProtocolProvidesNeither(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::never())->method('setChannelModes');
        $support = $this->support();
        $support->method('getChannelRegisteredModeLetter')->willReturn(null);
        $support->method('getPermanentChannelModeLetter')->willReturn(null);

        $this->actions($network, support: $support)->restoreRegistrationModes('#opers');
    }

    #[Test]
    public function restoresModeLockWithOnlyProtocolRequiredParameters(): void
    {
        $support = $this->support();
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k']);
        $modeActions = $this->createMock(ChannelModeActions::class);
        $modeActions->expects(self::once())->method('apply')->with(
            '#opers',
            self::callback(self::matchesExpectedModeLock(...)),
        );
        $modeLock = ChannelModeLock::active([
            new ChannelSetting(new ModeName('n'), 'ignored'),
            new ChannelSetting(new ModeName('k'), 'secretpass'),
            new ChannelSetting(new ModeName('l')),
        ]);

        $this->actions(modeActions: $modeActions, support: $support)->restoreModeLock('#opers', $modeLock);
    }

    #[Test]
    public function skipsInactiveAndEmptyModeLocks(): void
    {
        $modeActions = $this->createMock(ChannelModeActions::class);
        $modeActions->expects(self::never())->method('apply');
        $actions = $this->actions(modeActions: $modeActions);

        $actions->restoreModeLock('#opers', ChannelModeLock::inactive());
        $actions->restoreModeLock('#opers', ChannelModeLock::active());
    }

    #[Test]
    public function restoresStoredTopic(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::once())->method('setChannelTopic')->with('#opers', 'Official ops channel');

        $this->actions($network)->restoreTopic('#opers', 'Official ops channel');
    }

    #[Test]
    public function restoresHighestSupportedServiceRank(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::once())->method('setChannelMemberMode')->with('#opers', '001CS', 'a', true);
        $support = $this->support();
        $support->method('getSupportedPrefixModes')->willReturn(['v', 'o', 'a']);

        $this->actions($network, support: $support, uidRegistry: $this->uidRegistry('001CS'))
            ->restoreServiceRank('#opers');
    }

    #[Test]
    public function fallsBackToOperatorRankWhenNoKnownRankIsAdvertised(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::once())->method('setChannelMemberMode')->with('#opers', '001CS', 'o', true);
        $support = $this->support();
        $support->method('getSupportedPrefixModes')->willReturn([]);

        $this->actions($network, support: $support, uidRegistry: $this->uidRegistry('001CS'))
            ->restoreServiceRank('#opers');
    }

    #[Test]
    public function skipsServiceRankWhenChanServUidIsUnavailable(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::never())->method('setChannelMemberMode');

        $this->actions($network)->restoreServiceRank('#opers');
    }

    private function actions(
        ?ChannelServiceActionsPort $network = null,
        ?ChannelModeActions $modeActions = null,
        ?ChannelModeSupportInterface $support = null,
        ?ServiceUidRegistry $uidRegistry = null,
    ): IrcRegisteredChannelSetupActions {
        $support ??= $this->support();
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        return new IrcRegisteredChannelSetupActions(
            $network ?? $this->createStub(ChannelServiceActionsPort::class),
            $modeActions ?? $this->createStub(ChannelModeActions::class),
            $provider,
            $uidRegistry ?? ServiceUidRegistry::fromIterable([]),
        );
    }

    private function support(): ChannelModeSupportInterface&Stub
    {
        return $this->createStub(ChannelModeSupportInterface::class);
    }

    /** @param list<mixed> $changes */
    private static function matchesExpectedModeLock(array $changes): bool
    {
        $expected = [
            ['n', ModeChangeAction::Add, null],
            ['k', ModeChangeAction::Add, 'secretpass'],
            ['l', ModeChangeAction::Add, null],
        ];

        foreach ($expected as $index => $item) {
            $change = $changes[$index] ?? null;
            if (!$change instanceof ModeChange) {
                return false;
            }
            if ([$change->mode->value, $change->action, $change->parameter] !== $item) {
                return false;
            }
        }

        return count($expected) === count($changes);
    }

    private function uidRegistry(string $uid): ServiceUidRegistry
    {
        $provider = $this->createStub(ServiceUidProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('chanserv');
        $provider->method('getUid')->willReturn($uid);

        return ServiceUidRegistry::fromIterable([$provider]);
    }
}
