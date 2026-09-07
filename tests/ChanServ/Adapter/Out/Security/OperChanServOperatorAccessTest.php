<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Security;

use App\Application\OperServ\Port\In\IrcopAccessQuery;
use App\ChanServ\Adapter\Out\Security\OperChanServOperatorAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperChanServOperatorAccess::class)]
final class OperChanServOperatorAccessTest extends TestCase
{
    #[Test]
    public function delegatesToQuery(): void
    {
        $query = $this->createMock(IrcopAccessQuery::class);
        $query->expects(self::once())->method('isRoot')->with('root')->willReturn(true);
        $query->expects(self::once())->method('isIrcop')->with(1, 'oper')->willReturn(true);
        $query->expects(self::once())->method('hasPermission')->with(1, 'oper', 'perm')->willReturn(true);
        $query->expects(self::once())->method('hasAnyPermission')->with(1, 'oper', ['p1'])->willReturn(true);

        $access = new OperChanServOperatorAccess($query);

        self::assertTrue($access->isRoot('root'));
        self::assertTrue($access->isIrcop(1, 'oper'));
        self::assertTrue($access->hasPermission(1, 'oper', 'perm'));
        self::assertTrue($access->hasAnyPermission(1, 'oper', ['p1']));
    }
}
