<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Application\Service\ChannelUnsuspensionService;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlock;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementTrigger;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelUnsuspensionService::class)]
final class ChannelUnsuspensionServiceTest extends TestCase
{
    #[Test]
    public function returnsNullWithoutNetworkEffectsWhenChannelNoLongerExists(): void
    {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn(null);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::never())->method('isChannelOnNetwork');
        $suspension = $this->createMock(ChannelSuspensionService::class);
        $suspension->expects(self::never())->method('liftSuspension');
        $mlock = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $mlock->expects(self::never())->method('handle');

        self::assertNull($this->service($repository, $actions, $suspension, $mlock)->restore('#missing'));
    }

    #[Test]
    public function liftsThenEnforcesMlockWhenChannelIsOnNetwork(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#Test', 1, 'Test');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($channel);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#Test')->willReturn(true);
        $actions->expects(self::never())->method('joinChannelAsService');
        $sequence = [];
        $suspension = $this->createMock(ChannelSuspensionService::class);
        $suspension->expects(self::once())->method('liftSuspension')->with($channel)
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'lift';
            });
        $mlock = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $mlock->expects(self::once())->method('handle')->with(self::callback(
            static function (EnforceChannelMlock $command) use (&$sequence): bool {
                $sequence[] = 'mlock';

                return '#Test' === $command->channelName
                    && MlockEnforcementTrigger::ChannelUnsuspended === $command->trigger;
            },
        ))->willReturn(new MlockEnforcementResult(MlockEnforcementOutcome::NoChanges));

        self::assertSame('#Test', $this->service($repository, $actions, $suspension, $mlock)->restore('#test'));
        self::assertSame(['lift', 'mlock'], $sequence);
    }

    #[Test]
    public function recreatesMissingNetworkChannelBeforeLiftAndMlock(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#Test', 1, 'Test');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($channel);
        $sequence = [];
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#Test')->willReturn(false);
        $actions->expects(self::once())->method('joinChannelAsService')->with('#Test')
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'join';
            });
        $suspension = $this->createMock(ChannelSuspensionService::class);
        $suspension->expects(self::once())->method('liftSuspension')->with($channel)
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'lift';
            });
        $mlock = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $mlock->expects(self::once())->method('handle')
            ->willReturnCallback(static function () use (&$sequence): MlockEnforcementResult {
                $sequence[] = 'mlock';

                return new MlockEnforcementResult(MlockEnforcementOutcome::NoChanges);
            });
        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('joining to recreate it'));

        self::assertSame('#Test', $this->service($repository, $actions, $suspension, $mlock, $logger)->restore('#test'));
        self::assertSame(['join', 'lift', 'mlock'], $sequence);
    }

    private function service(
        RegisteredChannelRepositoryInterface $repository,
        ChanNetworkActions $actions,
        ChannelSuspensionService $suspension,
        EnforceChannelMlockHandlerInterface $mlock,
        ?ChanServActivitySink $logger = null,
    ): ChannelUnsuspensionService {
        return new ChannelUnsuspensionService(
            $repository,
            $actions,
            $suspension,
            $mlock,
            $logger ?? $this->createStub(ChanServActivitySink::class),
        );
    }
}
