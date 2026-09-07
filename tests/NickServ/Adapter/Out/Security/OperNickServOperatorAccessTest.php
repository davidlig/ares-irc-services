<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\Application\OperServ\Port\In\IrcopAccessQuery;
use App\NickServ\Adapter\Out\Security\OperNickServOperatorAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperNickServOperatorAccess::class)]
final class OperNickServOperatorAccessTest extends TestCase
{
    #[Test]
    public function delegatesSemanticChecksToOperServPublicQuery(): void
    {
        $query = $this->createMock(IrcopAccessQuery::class);
        $query->expects(self::once())->method('isRoot')->with('Root')->willReturn(true);
        $query->expects(self::once())->method('isIrcop')->with(42, 'Oper')->willReturn(true);
        $query->expects(self::once())->method('hasPermission')->with(42, 'Oper', 'nickserv.saset')->willReturn(true);
        $query->expects(self::once())->method('hasAnyPermission')->with(42, 'Oper', ['one', 'two'])->willReturn(true);
        $access = new OperNickServOperatorAccess($query);

        self::assertTrue($access->isRoot('Root'));
        self::assertTrue($access->isIrcop(42, 'Oper'));
        self::assertTrue($access->hasPermission(42, 'Oper', 'nickserv.saset'));
        self::assertTrue($access->hasAnyPermission(42, 'Oper', ['one', 'two']));
    }
}
