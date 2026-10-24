<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Domain\Entity;

use App\NickServ\Domain\Entity\RegistrationPolicy;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrationPolicy::class)]
final class RegistrationPolicyTest extends TestCase
{
    #[Test]
    public function singletonDefaultsToTokenAndChangesMode(): void
    {
        $policy = new RegistrationPolicy();
        self::assertSame(1, $policy->getId());
        self::assertSame(RegistrationMode::Token, $policy->getMode());
        $policy->changeMode(RegistrationMode::Email);
        self::assertSame(RegistrationMode::Email, $policy->getMode());
        $policy->changeMode(RegistrationMode::Email);
        self::assertSame(RegistrationMode::Email, $policy->getMode());
    }
}
