<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\NickServ\Adapter\Out\Security\OperNickServOperatorAccess;
use App\OperServ\Application\Port\In\IrcopAccessQuery;
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
        $query->expects(self::once())->method('isRoot')->with('Root', 7, true, false)->willReturn(true);
        $query->expects(self::once())->method('isIrcop')->with('Oper', 42, true, true)->willReturn(true);
        $query->expects(self::once())->method('hasPermission')->with('Oper', 42, true, true, 'nickserv.saset')->willReturn(true);
        $query->expects(self::once())->method('hasAnyPermission')->with('Oper', 42, true, true, ['one', 'two'])->willReturn(true);
        $access = new OperNickServOperatorAccess($query);

        self::assertTrue($access->isRoot('Root', 7, true, false));
        self::assertTrue($access->isIrcop('Oper', 42, true, true));
        self::assertTrue($access->hasPermission('Oper', 42, true, true, 'nickserv.saset'));
        self::assertTrue($access->hasAnyPermission('Oper', 42, true, true, ['one', 'two']));
    }
}
