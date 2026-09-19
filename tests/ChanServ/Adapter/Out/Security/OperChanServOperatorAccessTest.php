<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Security;

use App\ChanServ\Adapter\Out\Security\OperChanServOperatorAccess;
use App\OperServ\Application\Port\In\IrcopAccessQuery;
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
        $query->expects(self::once())->method('isRoot')->with('root', 1, true, false)->willReturn(true);
        $query->expects(self::once())->method('isIrcop')->with('oper', 1, true, true)->willReturn(true);
        $query->expects(self::once())->method('hasPermission')->with('oper', 1, true, true, 'perm')->willReturn(true);
        $query->expects(self::once())->method('hasAnyPermission')->with('oper', 1, true, true, ['p1'])->willReturn(true);

        $access = new OperChanServOperatorAccess($query);

        self::assertTrue($access->isRoot('root', 1, true, false));
        self::assertTrue($access->isIrcop('oper', 1, true, true));
        self::assertTrue($access->hasPermission('oper', 1, true, true, 'perm'));
        self::assertTrue($access->hasAnyPermission('oper', 1, true, true, ['p1']));
    }
}
