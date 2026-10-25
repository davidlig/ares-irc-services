<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\OperServ;

use App\ChanServ\Adapter\Out\OperServ\OperServEmailDomainPolicy;
use App\OperServ\Application\Port\In\EmailDomainPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServEmailDomainPolicy::class)]
final class OperServEmailDomainPolicyTest extends TestCase
{
    #[Test]
    public function delegatesOnlyThePublicSemanticQuery(): void
    {
        foreach ([true, false] as $blocked) {
            $upstream = $this->createMock(EmailDomainPolicy::class);
            $upstream->expects(self::once())->method('isBlocked')->with('user@example.org')->willReturn($blocked);
            self::assertSame($blocked, new OperServEmailDomainPolicy($upstream)->isBlocked('user@example.org'));
        }
    }
}
