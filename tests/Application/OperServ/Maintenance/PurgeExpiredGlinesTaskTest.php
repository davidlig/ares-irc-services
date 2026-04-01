<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Maintenance;

use App\Application\OperServ\Maintenance\PurgeExpiredGlinesTask;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PurgeExpiredGlinesTask::class)]
final class PurgeExpiredGlinesTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsOperservPurgeExpiredGlines(): void
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, new NullLogger(), 3600);

        self::assertSame('operserv.purge_expired_glines', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, new NullLogger(), 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns360(): void
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, new NullLogger(), 3600);

        self::assertSame(360, $task->getOrder());
    }

    #[Test]
    public function runRemovesExpiredGlines(): void
    {
        $expiredGline1 = $this->createStub(Gline::class);
        $expiredGline1->method('getMask')->willReturn('*@host1.com');
        $expiredGline1->method('getId')->willReturn(101);

        $expiredGline2 = $this->createStub(Gline::class);
        $expiredGline2->method('getMask')->willReturn('*@host2.com');
        $expiredGline2->method('getId')->willReturn(102);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findExpired')->willReturn([$expiredGline1, $expiredGline2]);
        $glineRepo->expects(self::exactly(2))->method('remove');

        $task = new PurgeExpiredGlinesTask($glineRepo, new NullLogger(), 3600);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredGlines(): void
    {
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findExpired')->willReturn([]);
        $glineRepo->expects(self::never())->method('remove');

        $task = new PurgeExpiredGlinesTask($glineRepo, new NullLogger(), 3600);
        $task->run();
    }

    #[Test]
    public function runRemovesCorrectGlineById(): void
    {
        $expiredGline = $this->createStub(Gline::class);
        $expiredGline->method('getMask')->willReturn('*@isp.com');
        $expiredGline->method('getId')->willReturn(999);

        $removed = [];
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findExpired')->willReturn([$expiredGline]);
        $glineRepo->method('remove')
            ->willReturnCallback(static function (Gline $gline) use (&$removed): void {
                $removed[] = $gline;
            });

        $task = new PurgeExpiredGlinesTask($glineRepo, new NullLogger(), 3600);
        $task->run();

        self::assertCount(1, $removed);
    }
}
