<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\PurgePendingDeletionNicknamesTask;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(PurgePendingDeletionNicknamesTask::class)]
final class PurgePendingDeletionNicknamesTaskTest extends TestCase
{
    #[Test]
    public function metadataReturnsExpectedValues(): void
    {
        $task = new PurgePendingDeletionNicknamesTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickDropService::class),
            $this->clock(),
            3600,
            7,
        );

        self::assertSame('nickserv.purge_pending_deletion_nicknames', $task->getName());
        self::assertSame(3600, $task->getIntervalSeconds());
        self::assertSame(210, $task->getOrder());
    }

    #[Test]
    public function runHardDropsExpiredPendingDeletionNicks(): void
    {
        $nick = $this->createStub(RegisteredNick::class);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findPendingDeletionBefore')
            ->with($this->now()->modify('-7 days'))
            ->willReturn([$nick, new stdClass()]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('hardDropNick')->with(
            $nick,
            $this->now(),
            'manual-grace-expired',
            null,
        );

        $task = new PurgePendingDeletionNicknamesTask($repo, $dropService, $this->clock(), 3600, 7);

        $task->run();
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());

        return $clock;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
