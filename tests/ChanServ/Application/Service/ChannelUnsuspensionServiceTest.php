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
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Shared\Domain\IrcIdentityFold;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
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
        $repository->method('findByChannelNameKey')->willReturn(null);
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
        $repository->method('findByChannelNameKey')->willReturn($channel);
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
        $repository->method('findByChannelNameKey')->willReturn($channel);
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

    #[Test]
    public function restoresAnAsciiNetworkChannelFromItsStoredKey(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#Ops[1]', 1, 'Ops');
        $ascii = NegotiatedCaseMapping::network(CaseMapping::Ascii);
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelNameKey')->willReturnCallback(
            static fn (string $name): ?RegisteredChannel => IrcIdentityFold::fold($name) === $channel->getNameLower() ? $channel : null,
        );
        $repository->method('findByChannelName')->willReturnCallback(
            static fn (string $name): ?RegisteredChannel => IrcIdentityFold::fold($name) === $channel->getNameLower() && $ascii->equivalent($channel->getName(), $name) ? $channel : null,
        );
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#Ops[1]')->willReturn(false);
        $actions->expects(self::once())->method('joinChannelAsService')->with('#Ops[1]');
        $suspension = $this->createMock(ChannelSuspensionService::class);
        $suspension->expects(self::once())->method('liftSuspension')->with($channel);
        $mlock = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $mlock->expects(self::once())->method('handle')
            ->with(self::callback(static fn (EnforceChannelMlock $command): bool => '#Ops[1]' === $command->channelName))
            ->willReturn(new MlockEnforcementResult(MlockEnforcementOutcome::NoChanges));

        self::assertSame('#Ops[1]', $this->service($repository, $actions, $suspension, $mlock)->restore('#ops{1}'));
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
