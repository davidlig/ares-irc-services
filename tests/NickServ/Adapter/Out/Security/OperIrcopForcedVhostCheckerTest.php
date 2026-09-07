<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\Application\OperServ\Port\In\ProtectedNickQuery;
use App\NickServ\Adapter\Out\Security\OperIrcopForcedVhostChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperIrcopForcedVhostChecker::class)]
final class OperIrcopForcedVhostCheckerTest extends TestCase
{
    #[Test]
    public function delegatesForcedVhostChecksToThePublicOperServQuery(): void
    {
        $query = $this->createMock(ProtectedNickQuery::class);
        $query->expects(self::once())->method('hasForcedVhost')->with(123)->willReturn(true);
        $query->expects(self::once())->method('resolveForcedVhost')->with(123, 'Alice')->willReturn('Alice.staff.example.com');
        $checker = new OperIrcopForcedVhostChecker($query);

        self::assertTrue($checker->hasForcedVhost(123));
        self::assertSame('Alice.staff.example.com', $checker->resolveForcedVhost(123, 'Alice'));
    }
}
