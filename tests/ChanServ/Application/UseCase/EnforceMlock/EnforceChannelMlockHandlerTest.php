<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\EnforceMlock;

use App\ChanServ\Application\Model\ChannelMlockNetworkState;
use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Port\Out\ChannelMlockNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelModeActions;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlock;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandler;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementTrigger;
use App\ChanServ\Application\UseCase\EnforceMlock\SynchronizeAllChannelMlocksHandler;
use App\ChanServ\Domain\Policy\MlockReconciliationPolicy;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeCapability;
use App\ChanServ\Domain\ValueObject\ModeChange;
use App\ChanServ\Domain\ValueObject\ModeChangeAction;
use App\ChanServ\Domain\ValueObject\ModeName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(EnforceChannelMlock::class)]
#[CoversClass(EnforceChannelMlockHandler::class)]
#[CoversClass(MlockEnforcementResult::class)]
#[CoversClass(SynchronizeAllChannelMlocksHandler::class)]
final class EnforceChannelMlockHandlerTest extends TestCase
{
    #[Test]
    public function channelSyncWaitsForNetworkSynchronizationBeforeLoadingPolicy(): void
    {
        $policies = $this->createMock(ChannelMlockPolicyRepository::class);
        $policies->expects(self::never())->method('findByName');
        $network = $this->createStub(ChannelMlockNetworkQuery::class);
        $network->method('synchronizationComplete')->willReturn(false);

        $result = $this->handler($policies, $network, $this->createStub(ChannelModeActions::class))->handle(
            new EnforceChannelMlock('#test', MlockEnforcementTrigger::ChannelSynchronized),
        );

        self::assertSame(MlockEnforcementOutcome::SynchronizationPending, $result->outcome);
    }

    #[Test]
    public function reportsMissingBlockedAndInactiveChannels(): void
    {
        $policies = $this->createStub(ChannelMlockPolicyRepository::class);
        $policies->method('findByName')->willReturnOnConsecutiveCalls(
            null,
            $this->policy(blocked: true),
            $this->policy(lock: ChannelModeLock::inactive()),
        );
        $network = $this->createMock(ChannelMlockNetworkQuery::class);
        $network->expects(self::never())->method('findChannel');
        $handler = $this->handler($policies, $network, $this->createStub(ChannelModeActions::class));

        self::assertSame(MlockEnforcementOutcome::ChannelUnavailable, $handler->handle($this->command())->outcome);
        self::assertSame(MlockEnforcementOutcome::ChannelBlocked, $handler->handle($this->command())->outcome);
        self::assertSame(MlockEnforcementOutcome::LockInactive, $handler->handle($this->command())->outcome);
    }

    #[Test]
    public function reportsUnavailableNetworkAndNoChanges(): void
    {
        $network = $this->createStub(ChannelMlockNetworkQuery::class);
        $network->method('findChannel')->willReturnOnConsecutiveCalls(null, new ChannelMlockNetworkState('#test', [], []));
        $handler = $this->handler($this->repository($this->policy()), $network, $this->createStub(ChannelModeActions::class));

        self::assertSame(MlockEnforcementOutcome::ChannelUnavailable, $handler->handle($this->command())->outcome);
        self::assertSame(MlockEnforcementOutcome::NoChanges, $handler->handle($this->command())->outcome);
    }

    #[Test]
    public function appliesSemanticModeChanges(): void
    {
        $n = new ModeName('no_external_messages');
        $m = new ModeName('moderated');
        $lock = ChannelModeLock::active([new ChannelSetting($n)]);
        $network = $this->createStub(ChannelMlockNetworkQuery::class);
        $network->method('findChannel')->willReturn(new ChannelMlockNetworkState(
            '#test',
            [new ChannelSetting($m)],
            [new ModeCapability($n), new ModeCapability($m)],
        ));
        $actions = $this->createMock(ChannelModeActions::class);
        $actions->expects(self::once())->method('apply')->with('#test', self::callback(static function (array $changes): bool {
            $first = $changes[0] ?? null;
            $second = $changes[1] ?? null;

            return 2 === count($changes)
                && $first instanceof ModeChange
                && $second instanceof ModeChange
                && ModeChangeAction::Remove === $first->action
                && ModeChangeAction::Add === $second->action;
        }));

        $result = $this->handler($this->repository($this->policy(lock: $lock)), $network, $actions)->handle($this->command());

        self::assertSame(MlockEnforcementOutcome::Applied, $result->outcome);
        self::assertSame(2, $result->changeCount);
    }

    #[Test]
    public function knownPolicyStillHonoursSyncTimingAndPolicyState(): void
    {
        $network = $this->createMock(ChannelMlockNetworkQuery::class);
        $network->expects(self::once())->method('synchronizationComplete')->willReturn(false);
        $network->expects(self::never())->method('findChannel');
        $handler = $this->handler(
            $this->createStub(ChannelMlockPolicyRepository::class),
            $network,
            $this->createStub(ChannelModeActions::class),
        );

        $pending = $handler->handleKnownPolicy(
            new EnforceChannelMlock('#test', MlockEnforcementTrigger::ChannelSynchronized),
            $this->policy(),
        );
        $blocked = $handler->handleKnownPolicy($this->command(), $this->policy(blocked: true));

        self::assertSame(MlockEnforcementOutcome::SynchronizationPending, $pending->outcome);
        self::assertSame(MlockEnforcementOutcome::ChannelBlocked, $blocked->outcome);
    }

    #[Test]
    public function synchronizesOnlyActiveUnblockedLocksAndCountsChanges(): void
    {
        $repository = $this->createStub(ChannelMlockPolicyRepository::class);
        $repository->method('all')->willReturn([
            $this->policy('#active'),
            $this->policy('#blocked', blocked: true),
            $this->policy('#inactive', lock: ChannelModeLock::inactive()),
        ]);
        $enforcer = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $enforcer->expects(self::once())->method('handleKnownPolicy')->with(
            self::callback(static fn (EnforceChannelMlock $command): bool => '#active' === $command->channelName),
            self::callback(static fn (ChannelMlockPolicy $policy): bool => '#active' === $policy->name),
        )->willReturn(new MlockEnforcementResult(MlockEnforcementOutcome::Applied, 2));

        self::assertSame(2, new SynchronizeAllChannelMlocksHandler($repository, $enforcer)->handle());
    }

    private function command(): EnforceChannelMlock
    {
        return new EnforceChannelMlock('#test', MlockEnforcementTrigger::ModesChanged);
    }

    private function policy(string $name = '#test', bool $blocked = false, ?ChannelModeLock $lock = null): ChannelMlockPolicy
    {
        return new ChannelMlockPolicy(
            $name,
            $blocked,
            $lock ?? ChannelModeLock::active(),
        );
    }

    private function repository(ChannelMlockPolicy $policy): ChannelMlockPolicyRepository
    {
        $repository = $this->createStub(ChannelMlockPolicyRepository::class);
        $repository->method('findByName')->willReturn($policy);

        return $repository;
    }

    private function handler(
        ChannelMlockPolicyRepository $policies,
        ChannelMlockNetworkQuery $network,
        ChannelModeActions $actions,
    ): EnforceChannelMlockHandler {
        return new EnforceChannelMlockHandler($policies, $network, $actions, new MlockReconciliationPolicy());
    }
}
