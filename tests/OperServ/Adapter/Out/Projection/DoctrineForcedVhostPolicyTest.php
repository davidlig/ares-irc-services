<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\DoctrineForcedVhostPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineForcedVhostPolicy::class)]
final class DoctrineForcedVhostPolicyTest extends TestCase
{
    #[Test]
    public function delegatesValidationToTheDomainPolicy(): void
    {
        $policy = new DoctrineForcedVhostPolicy();

        self::assertTrue($policy->isValid('staff.example.net'));
        self::assertFalse($policy->isValid('not a vhost'));
    }
}
