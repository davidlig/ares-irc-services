<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Policy;

use App\NickServ\Adapter\Out\Policy\EmailVerificationPolicy;
use App\NickServ\Application\Port\Out\EmailVerificationSettingRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailVerificationPolicy::class)]
final class EmailVerificationPolicyTest extends TestCase
{
    #[Test]
    public function readsThePolicyFromTheSettingRepository(): void
    {
        $settings = $this->createMock(EmailVerificationSettingRepositoryInterface::class);
        $settings->method('isVerificationRequired')->willReturn(false);
        $settings->expects(self::never())->method('setVerificationRequired');

        self::assertFalse(new EmailVerificationPolicy($settings)->isVerificationRequired());
    }

    #[Test]
    public function reportsARequiredPolicy(): void
    {
        $settings = $this->createStub(EmailVerificationSettingRepositoryInterface::class);
        $settings->method('isVerificationRequired')->willReturn(true);

        self::assertTrue(new EmailVerificationPolicy($settings)->isVerificationRequired());
    }

    #[Test]
    public function writesThePolicyThroughToTheSettingRepository(): void
    {
        $settings = $this->createMock(EmailVerificationSettingRepositoryInterface::class);
        $settings->expects(self::once())->method('setVerificationRequired')->with(false);

        new EmailVerificationPolicy($settings)->setVerificationRequired(false);
    }
}
