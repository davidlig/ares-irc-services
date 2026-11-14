<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServBurstSynchronizationSubscriber;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\UseCase\SynchronizeBurst\SynchronizeBurstChannels;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\OperServ\Adapter\In\Event\OperServGlineEnforceSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ChanServBurstSynchronizationSubscriber::class)]
final class ChanServBurstSynchronizationSubscriberTest extends TestCase
{
    #[Test]
    public function completesBurstThroughTheSingleCoordinator(): void
    {
        self::assertSame([NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 10]], ChanServBurstSynchronizationSubscriber::getSubscribedEvents());
        $handler = $this->createMock(SynchronizeBurstChannels::class);
        $scope = new BurstRankEventScope();
        $handler->expects(self::once())->method('handle')->with(self::isInstanceOf(DateTimeImmutable::class))->willReturnCallback(static function () use ($scope): void {
            self::assertTrue($scope->active());
        });
        new ChanServBurstSynchronizationSubscriber($handler, $scope)->onSyncComplete();
        self::assertFalse($scope->active());
    }

    #[Test]
    public function runsTheChanServPassBeforeOperServGlineEnforcement(): void
    {
        // Intended since #166: the single ChanServ end-of-burst pass runs before G-line enforcement.
        $chanServ = ChanServBurstSynchronizationSubscriber::getSubscribedEvents()[NetworkSynchronizationCompletedEvent::class];
        $gline = OperServGlineEnforceSubscriber::getSubscribedEvents()[NetworkSynchronizationCompletedEvent::class];
        self::assertIsArray($chanServ);
        self::assertIsArray($gline);
        self::assertGreaterThan($gline[1], $chanServ[1]);
    }

    #[Test]
    public function clearsScopeWhenReconciliationFails(): void
    {
        $handler = $this->createStub(SynchronizeBurstChannels::class);
        $handler->method('handle')->willThrowException(new RuntimeException('failed'));
        $scope = new BurstRankEventScope();
        try {
            new ChanServBurstSynchronizationSubscriber($handler, $scope)->onSyncComplete();
            self::fail('The reconciliation failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('failed', $error->getMessage());
        }
        self::assertFalse($scope->active());
    }
}
