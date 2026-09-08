<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Security;

use App\NickServ\Application\Security\IdentifiedAccountOwnerPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifiedAccountOwnerPolicy::class)]
final class IdentifiedAccountOwnerPolicyTest extends TestCase
{
    #[Test]
    public function grantsOnlyToTheIdentifiedOwningAccount(): void
    {
        $policy = new IdentifiedAccountOwnerPolicy();

        self::assertTrue($policy->allows(true, 10, 10));
        self::assertFalse($policy->allows(false, 10, 10));
        self::assertFalse($policy->allows(true, null, 10));
        self::assertFalse($policy->allows(true, 11, 10));
    }
}
