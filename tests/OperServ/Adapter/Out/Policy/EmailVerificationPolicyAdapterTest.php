<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Policy;

use App\NickServ\Application\Port\In\EmailVerificationPolicyInterface;
use App\OperServ\Adapter\Out\Policy\EmailVerificationPolicyAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailVerificationPolicyAdapter::class)]
final class EmailVerificationPolicyAdapterTest extends TestCase
{
    #[Test]
    public function delegatesTheReadToTheProviderPort(): void
    {
        $provider = $this->createMock(EmailVerificationPolicyInterface::class);
        $provider->method('isVerificationRequired')->willReturn(false);
        $provider->expects(self::never())->method('setVerificationRequired');

        self::assertFalse(new EmailVerificationPolicyAdapter($provider)->isVerificationRequired());
    }

    #[Test]
    public function delegatesTheWriteToTheProviderPort(): void
    {
        $provider = $this->createMock(EmailVerificationPolicyInterface::class);
        $provider->expects(self::once())->method('setVerificationRequired')->with(true);

        new EmailVerificationPolicyAdapter($provider)->setVerificationRequired(true);
    }
}
