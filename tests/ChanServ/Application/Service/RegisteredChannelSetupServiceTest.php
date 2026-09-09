<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelSetupActions;
use App\ChanServ\Application\Service\RegisteredChannelSetupService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegisteredChannelSetupService::class)]
final class RegisteredChannelSetupServiceTest extends TestCase
{
    #[Test]
    public function ignoresUnavailableAndBlockedChannels(): void
    {
        $blocked = RegisteredChannel::register(new DateTimeImmutable(), '#opers', 1, 'Debug');
        $blocked->suspend('maintenance');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturnOnConsecutiveCalls(null, $blocked);
        $network = $this->createMock(ChanNetworkActions::class);
        $network->expects(self::never())->method('isChannelOnNetwork');
        $actions = $this->createMock(RegisteredChannelSetupActions::class);
        $actions->expects(self::never())->method('restoreServiceRank');
        $service = new RegisteredChannelSetupService(
            $channels,
            $this->createStub(ChannelMlockPolicyRepository::class),
            $network,
            $actions,
        );

        $service->restore('#OPERS');
        $service->restore('#OPERS');
    }

    #[Test]
    public function existingNetworkChannelRestoresOnlyTheServiceRank(): void
    {
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#opers')->willReturn(
            RegisteredChannel::register(new DateTimeImmutable(), '#opers', 1, 'Debug'),
        );
        $modeLocks = $this->createMock(ChannelMlockPolicyRepository::class);
        $modeLocks->expects(self::never())->method('findByName');
        $network = $this->createStub(ChanNetworkActions::class);
        $network->method('isChannelOnNetwork')->willReturn(true);
        $actions = $this->createMock(RegisteredChannelSetupActions::class);
        $actions->expects(self::never())->method('restoreRegistrationModes');
        $actions->expects(self::never())->method('restoreModeLock');
        $actions->expects(self::never())->method('restoreTopic');
        $actions->expects(self::once())->method('restoreServiceRank')->with('#OPERS');

        new RegisteredChannelSetupService($channels, $modeLocks, $network, $actions)->restore('#OPERS');
    }

    #[Test]
    public function missingNetworkChannelRestoresItsCompleteRegisteredSetup(): void
    {
        $registered = RegisteredChannel::register(new DateTimeImmutable(), '#opers', 1, 'Debug');
        $registered->updateTopic('Official ops channel', new DateTimeImmutable('2026-01-01 00:00:00'));
        $modeLock = ChannelModeLock::active([
            new ChannelSetting(new ModeName('n')),
            new ChannelSetting(new ModeName('t')),
            new ChannelSetting(new ModeName('k'), 'secretpass'),
        ]);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($registered);
        $modeLocks = $this->createStub(ChannelMlockPolicyRepository::class);
        $modeLocks->method('findByName')->willReturn(new ChannelMlockPolicy('#opers', false, $modeLock));
        $network = $this->createStub(ChanNetworkActions::class);
        $network->method('isChannelOnNetwork')->willReturn(false);
        $actions = $this->createMock(RegisteredChannelSetupActions::class);
        $actions->expects(self::once())->method('restoreRegistrationModes')->with('#opers');
        $actions->expects(self::once())->method('restoreModeLock')->with('#opers', $modeLock);
        $actions->expects(self::once())->method('restoreTopic')->with('#opers', 'Official ops channel');
        $actions->expects(self::once())->method('restoreServiceRank')->with('#opers');

        new RegisteredChannelSetupService($channels, $modeLocks, $network, $actions)->restore('#opers');
    }

    #[Test]
    public function missingNetworkChannelSkipsInactiveModeLockAndAbsentTopic(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(RegisteredChannel::register(new DateTimeImmutable(), '#opers', 1, 'Debug'));
        $modeLocks = $this->createStub(ChannelMlockPolicyRepository::class);
        $modeLocks->method('findByName')->willReturn(new ChannelMlockPolicy(
            '#opers',
            false,
            ChannelModeLock::inactive(),
        ));
        $network = $this->createStub(ChanNetworkActions::class);
        $network->method('isChannelOnNetwork')->willReturn(false);
        $actions = $this->createMock(RegisteredChannelSetupActions::class);
        $actions->expects(self::once())->method('restoreRegistrationModes');
        $actions->expects(self::never())->method('restoreModeLock');
        $actions->expects(self::never())->method('restoreTopic');
        $actions->expects(self::once())->method('restoreServiceRank');

        new RegisteredChannelSetupService($channels, $modeLocks, $network, $actions)->restore('#opers');
    }

    #[Test]
    public function missingModeLockPolicyStillRestoresOtherSetup(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(RegisteredChannel::register(new DateTimeImmutable(), '#opers', 1, 'Debug'));
        $modeLocks = $this->createStub(ChannelMlockPolicyRepository::class);
        $modeLocks->method('findByName')->willReturn(null);
        $network = $this->createStub(ChanNetworkActions::class);
        $network->method('isChannelOnNetwork')->willReturn(false);
        $actions = $this->createMock(RegisteredChannelSetupActions::class);
        $actions->expects(self::once())->method('restoreRegistrationModes');
        $actions->expects(self::never())->method('restoreModeLock');
        $actions->expects(self::once())->method('restoreServiceRank');

        new RegisteredChannelSetupService($channels, $modeLocks, $network, $actions)->restore('#opers');
    }
}
