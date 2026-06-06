<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance;

use App\Application\NickServ\Maintenance\PurgePendingDeletionNicknamesTask;
use App\Application\NickServ\Service\NickDropService;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
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
            ->with(self::callback(static function (DateTimeImmutable $threshold): bool {
                $expected = new DateTimeImmutable()->modify('-7 days');

                return $expected->format('Y-m-d') === $threshold->format('Y-m-d');
            }))
            ->willReturn([$nick, new stdClass()]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('hardDropNick')->with($nick, 'manual-grace-expired', null);

        $task = new PurgePendingDeletionNicknamesTask($repo, $dropService, 3600, 7);

        $task->run();
    }
}
