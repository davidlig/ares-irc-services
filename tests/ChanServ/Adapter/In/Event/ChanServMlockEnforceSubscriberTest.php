<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\ChanServ\Adapter\In\Event\ChanServMlockEnforceSubscriber;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlock;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementTrigger;
use App\ChanServ\Application\UseCase\EnforceMlock\SynchronizeAllChannelMlocksHandler;
use App\Irc\Application\PublishedEvent\ChannelSettingsChangedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServMlockEnforceSubscriber::class)]
final class ChanServMlockEnforceSubscriberTest extends TestCase
{
    #[Test]
    public function itSubscribesToTheExactEventContractAndPriorities(): void
    {
        self::assertSame([
            NetworkSynchronizationCompletedEvent::class => ['onNetworkSynchronizationCompleted', -10],
            ChannelSynchronizedEvent::class => ['onChannelSynced', -10],
            ChannelSettingsChangedEvent::class => ['onChannelModesChanged', 255],
            ChannelMlockUpdatedEvent::class => ['onMlockUpdated', 0],
        ], ChanServMlockEnforceSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function itTranslatesEveryChannelEventToItsSemanticTrigger(): void
    {
        $commands = [];
        $handler = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $handler->expects(self::exactly(3))->method('handle')
            ->willReturnCallback(static function (EnforceChannelMlock $command) use (&$commands): MlockEnforcementResult {
                $commands[] = $command;

                return new MlockEnforcementResult(MlockEnforcementOutcome::NoChanges);
            });
        $subscriber = $this->subscriber($handler);

        $subscriber->onChannelSynced(new ChannelSynchronizedEvent('#Synced'));
        $subscriber->onChannelModesChanged(new ChannelSettingsChangedEvent('#Modes'));
        $subscriber->onMlockUpdated(new ChannelMlockUpdatedEvent('#Configured'));

        self::assertSame([
            ['#Synced', MlockEnforcementTrigger::ChannelSynchronized],
            ['#Modes', MlockEnforcementTrigger::ModesChanged],
            ['#Configured', MlockEnforcementTrigger::LockUpdated],
        ], array_map(
            static fn (EnforceChannelMlock $command): array => [$command->channelName, $command->trigger],
            $commands,
        ));
    }

    #[Test]
    public function networkSynchronizationDelegatesToTheBulkUseCase(): void
    {
        $handler = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $policies = $this->createMock(ChannelMlockPolicyRepository::class);
        $policies->expects(self::once())->method('all')->willReturn([]);
        $subscriber = $this->subscriber($handler, $policies);

        $subscriber->onNetworkSynchronizationCompleted();
    }

    private function subscriber(
        EnforceChannelMlockHandlerInterface $handler,
        ?ChannelMlockPolicyRepository $policies = null,
    ): ChanServMlockEnforceSubscriber {
        if (null === $policies) {
            $policyStub = $this->createStub(ChannelMlockPolicyRepository::class);
            $policyStub->method('all')->willReturn([]);
            $policies = $policyStub;
        }

        return new ChanServMlockEnforceSubscriber(
            $handler,
            new SynchronizeAllChannelMlocksHandler($policies, $handler),
        );
    }
}
