<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Legacy;

use App\OperServ\Adapter\Out\Legacy\LegacyForcedVhostPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyForcedVhostPolicy::class)]
final class LegacyForcedVhostPolicyTest extends TestCase
{
    #[Test]
    public function delegatesValidationToTheDomainPolicy(): void
    {
        $policy = new LegacyForcedVhostPolicy();

        self::assertTrue($policy->isValid('staff.example.net'));
        self::assertFalse($policy->isValid('not a vhost'));
    }
}
