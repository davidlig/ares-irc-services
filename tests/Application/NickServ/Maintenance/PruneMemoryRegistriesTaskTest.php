<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\NickServ\Maintenance\PruneMemoryRegistriesTask;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(PruneMemoryRegistriesTask::class)]
final class PruneMemoryRegistriesTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsTaskName(): void
    {
        $task = new PruneMemoryRegistriesTask(
            [],
            $this->createStub(LoggerInterface::class),
            3600,
        );

        self::assertSame('nickserv.prune_memory_registries', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsAndGetOrderReturnInjectedValues(): void
    {
        $task = new PruneMemoryRegistriesTask(
            [],
            $this->createStub(LoggerInterface::class),
            7200,
        );

        self::assertSame(7200, $task->getIntervalSeconds());
        self::assertSame(110, $task->getOrder());
    }

    #[Test]
    public function runCallsPruneOnEachPrunableAndSumsTotal(): void
    {
        $prunable1 = $this->createMock(InMemoryPrunableInterface::class);
        $prunable1->expects(self::once())->method('prune')->willReturn(2);
        $prunable2 = $this->createMock(InMemoryPrunableInterface::class);
        $prunable2->expects(self::once())->method('prune')->willReturn(3);

        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $task = new PruneMemoryRegistriesTask(
            [$prunable1, $prunable2],
            $logger,
            3600,
        );
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('pruned 5 stale', $logMessages[0]);
    }

    #[Test]
    public function runDoesNotLogWhenTotalIsZero(): void
    {
        $prunable = $this->createMock(InMemoryPrunableInterface::class);
        $prunable->method('prune')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new PruneMemoryRegistriesTask([$prunable], $logger, 3600);
        $task->run();
    }
}
