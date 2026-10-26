<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Entity;

use App\ChanServ\Domain\Entity\RegistrationPolicy;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrationPolicy::class)]
final class RegistrationPolicyTest extends TestCase
{
    #[Test]
    public function singletonDefaultsToDirectAndChangesMode(): void
    {
        $policy = new RegistrationPolicy();
        self::assertSame(1, $policy->getId());
        self::assertSame(RegistrationMode::Direct, $policy->getMode());
        $policy->changeMode(RegistrationMode::Approval);
        self::assertSame(RegistrationMode::Approval, $policy->getMode());
        $policy->changeMode(RegistrationMode::Approval);
        self::assertSame(RegistrationMode::Approval, $policy->getMode());
    }
}
