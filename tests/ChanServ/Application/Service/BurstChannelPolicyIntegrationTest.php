<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChannelRegistrationNetworkActions;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelRegistrationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRegistrationService::class)]
#[CoversClass(ChannelForbiddenService::class)]
final class BurstChannelPolicyIntegrationTest extends TestCase
{
    #[Test]
    public function registrationModesReuseKnownNamesWithoutAnotherScan(): void
    {
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::never())->method('iterateAll');
        $network = $this->createMock(ChannelRegistrationNetworkActions::class);
        $network->method('supportsRegisteredMode')->willReturn(true);
        $network->method('supportsPermanentMode')->willReturn(true);
        $network->expects(self::once())->method('reconcileRegisteredMode')->with(['#a' => true], ['#A']);
        $network->expects(self::once())->method('reconcilePermanentMode')->with(['#a' => true], ['#A']);
        new ChannelRegistrationService($repo, $network)->reconcileKnownChannels(['#a' => true], ['#A']);
    }

    #[Test]
    public function unsupportedRegistrationModesAreNotApplied(): void
    {
        $network = $this->createMock(ChannelRegistrationNetworkActions::class);
        $network->method('supportsRegisteredMode')->willReturn(false);
        $network->method('supportsPermanentMode')->willReturn(false);
        $network->expects(self::never())->method('reconcileRegisteredMode');
        $network->expects(self::never())->method('reconcilePermanentMode');
        new ChannelRegistrationService($this->createStub(RegisteredChannelRepositoryInterface::class), $network)->reconcileKnownChannels([], []);
    }

    #[Test]
    public function knownForbiddenChannelAvoidsAnotherRepositoryLookup(): void
    {
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::never())->method('findByChannelName');
        $network = $this->createMock(ChanNetworkActions::class);
        $network->method('isChannelOnNetwork')->willReturn(true);
        $network->method('getChannelTimestamp')->willReturn(100);
        $network->method('getChannelMemberUids')->willReturn(['U1']);
        $network->expects(self::once())->method('joinChannelAsService')->with('#bad', 100);
        $network->expects(self::once())->method('kickFromChannel')->with('#bad', 'U1', 'Forbidden channel');
        $network->expects(self::once())->method('enforceForbiddenModes')->with('#bad', 100);
        new ChannelForbiddenService($repo, $this->createStub(ChanDropService::class), $network, $this->createStub(ChanServEventPublisher::class), $this->createStub(ChanServActivitySink::class))
            ->enforceKnownForbiddenChannel('#bad');
    }
}
