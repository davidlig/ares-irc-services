<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Domain\Entity;

use App\NickServ\Domain\Entity\EmailVerificationSetting;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(EmailVerificationSetting::class)]
final class EmailVerificationSettingTest extends TestCase
{
    #[Test]
    public function exposesTheConstructedPolicy(): void
    {
        self::assertTrue(new EmailVerificationSetting(true)->isRequired());
        self::assertFalse(new EmailVerificationSetting(false)->isRequired());
    }

    #[Test]
    public function changeUpdatesThePolicy(): void
    {
        $setting = new EmailVerificationSetting(true);

        $setting->change(false);
        self::assertFalse($setting->isRequired());

        $setting->change(true);
        self::assertTrue($setting->isRequired());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $setting = new EmailVerificationSetting(true);

        $reflection = new ReflectionClass($setting);
        $reflection->getProperty('id')->setValue($setting, 42);

        self::assertSame(42, $setting->getId());
    }
}
