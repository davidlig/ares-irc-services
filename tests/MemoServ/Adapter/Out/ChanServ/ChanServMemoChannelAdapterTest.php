<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\ChanServ;

use App\ChanServ\Application\Port\In\MemoChannel;
use App\ChanServ\Application\Port\In\MemoChannelQuery;
use App\MemoServ\Adapter\Out\ChanServ\ChanServMemoChannelAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServMemoChannelAdapter::class)]
final class ChanServMemoChannelAdapterTest extends TestCase
{
    #[Test]
    public function mapsPublicChanServChannelToMemoServView(): void
    {
        $query = $this->createMock(MemoChannelQuery::class);
        $query->expects(self::once())->method('findByName')->with('#PHP')->willReturn(new MemoChannel(10, '#php'));

        $view = new ChanServMemoChannelAdapter($query)->findChannelByName('#PHP');

        self::assertNotNull($view);
        self::assertSame(10, $view->id);
        self::assertSame('#php', $view->name);
    }

    #[Test]
    public function returnsNullWhenChanServDoesNotExposeChannel(): void
    {
        $query = $this->createMock(MemoChannelQuery::class);
        $query->expects(self::once())->method('findByName')->with('#unknown')->willReturn(null);

        self::assertNull(new ChanServMemoChannelAdapter($query)->findChannelByName('#unknown'));
    }

    #[Test]
    public function delegatesChannelPermissionsToPublicChanServQuery(): void
    {
        $query = $this->createMock(MemoChannelQuery::class);
        $query->expects(self::once())->method('canRead')->with(10, 5)->willReturn(true);
        $query->expects(self::once())->method('canManage')->with(10, 5)->willReturn(false);
        $query->expects(self::once())->method('isFounder')->with(10, 5)->willReturn(false);

        $adapter = new ChanServMemoChannelAdapter($query);

        self::assertTrue($adapter->canReadChannelMemos(10, 5));
        self::assertFalse($adapter->canManageChannelMemos(10, 5));
        self::assertFalse($adapter->isChannelFounder(10, 5));
    }
}
