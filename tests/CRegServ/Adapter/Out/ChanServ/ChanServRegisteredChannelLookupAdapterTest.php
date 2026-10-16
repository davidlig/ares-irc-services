<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\Out\ChanServ;

use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\CRegServ\Adapter\Out\ChanServ\ChanServRegisteredChannelLookupAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lookup that stops REGISTRA queuing a channel ChanServ already holds reads through
 * ChanServ's public projection, so a name nobody holds must read as free and one somebody
 * holds must read as taken.
 */
#[CoversClass(ChanServRegisteredChannelLookupAdapter::class)]
final class ChanServRegisteredChannelLookupAdapterTest extends TestCase
{
    private static function projection(string $name): ChannelProjection
    {
        return new ChannelProjection(
            id: 11,
            name: $name,
            founderNickId: 7,
            topic: null,
            mlockActive: false,
            mlock: '',
            mlockParams: [],
            topicLock: false,
            forbidden: false,
            forbiddenReason: null,
            suspended: false,
            pendingDeletion: false,
        );
    }

    #[Test]
    public function reportsAChannelChanServAlreadyHoldsAsTaken(): void
    {
        $query = $this->createStub(ChannelProjectionQuery::class);
        $query->method('findByName')->willReturn(self::projection('#SitioChat'));

        $adapter = new ChanServRegisteredChannelLookupAdapter($query);

        self::assertTrue($adapter->existsByChannelName('#SitioChat'));
    }

    #[Test]
    public function reportsAChannelNobodyHoldsAsFree(): void
    {
        $query = $this->createStub(ChannelProjectionQuery::class);
        $query->method('findByName')->willReturn(null);

        $adapter = new ChanServRegisteredChannelLookupAdapter($query);

        self::assertFalse($adapter->existsByChannelName('#SinRegistrar'));
    }

    #[Test]
    public function asksTheProjectionWithTheNameItWasGiven(): void
    {
        $asked = [];
        $query = $this->createMock(ChannelProjectionQuery::class);
        $query->expects(self::once())
            ->method('findByName')
            ->with('#SitioChat')
            ->willReturnCallback(static function (string $name) use (&$asked): ?ChannelProjection {
                $asked[] = $name;

                return null;
            });

        $adapter = new ChanServRegisteredChannelLookupAdapter($query);

        self::assertFalse($adapter->existsByChannelName('#SitioChat'));
        self::assertSame(['#SitioChat'], $asked);
    }

    #[Test]
    public function reportsAChannelPendingDeletionAsPendingDeletion(): void
    {
        $query = $this->createStub(ChannelProjectionQuery::class);
        $query->method('findByName')->willReturn(new ChannelProjection(
            id: 11,
            name: '#SitioChat',
            founderNickId: 7,
            topic: null,
            mlockActive: false,
            mlock: '',
            mlockParams: [],
            topicLock: false,
            forbidden: false,
            forbiddenReason: null,
            suspended: false,
            pendingDeletion: true,
        ));

        $adapter = new ChanServRegisteredChannelLookupAdapter($query);

        self::assertTrue($adapter->isPendingDeletion('#SitioChat'));
    }

    #[Test]
    public function reportsAChannelNotPendingDeletionAsNotPendingDeletion(): void
    {
        $query = $this->createStub(ChannelProjectionQuery::class);
        $query->method('findByName')->willReturn(self::projection('#SitioChat'));

        $adapter = new ChanServRegisteredChannelLookupAdapter($query);

        self::assertFalse($adapter->isPendingDeletion('#SitioChat'));
    }
}
