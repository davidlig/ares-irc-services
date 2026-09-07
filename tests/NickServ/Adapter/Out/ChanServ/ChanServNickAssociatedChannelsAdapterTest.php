<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\ChanServ;

use App\Application\ChanServ\Port\In\NickChannelAssociation;
use App\Application\ChanServ\Port\In\NickChannelAssociationQuery;
use App\NickServ\Adapter\Out\ChanServ\ChanServNickAssociatedChannelsAdapter;
use App\NickServ\Application\Port\Out\AssociatedChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServNickAssociatedChannelsAdapter::class)]
#[CoversClass(AssociatedChannel::class)]
final class ChanServNickAssociatedChannelsAdapterTest extends TestCase
{
    #[Test]
    public function mapsChanServAssociationsToNickServModel(): void
    {
        $query = $this->createStub(NickChannelAssociationQuery::class);
        $query->method('findForNick')->willReturn([
            new NickChannelAssociation('#ares', 'access', 50),
        ]);

        $result = new ChanServNickAssociatedChannelsAdapter($query)->findChannelsForNick(42);

        self::assertCount(1, $result);
        self::assertSame('#ares', $result[0]->name);
        self::assertSame('access', $result[0]->type);
        self::assertSame(50, $result[0]->level);
    }
}
