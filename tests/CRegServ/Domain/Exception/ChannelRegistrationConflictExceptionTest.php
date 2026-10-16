<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Domain\Exception;

use App\CRegServ\Domain\Exception\ChannelRegistrationConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ChannelRegistrationConflictException::class)]
final class ChannelRegistrationConflictExceptionTest extends TestCase
{
    #[Test]
    public function forChannelCreatesExceptionWithTheLoweredChannelName(): void
    {
        $exception = ChannelRegistrationConflictException::forChannel('#sitiochat');

        self::assertStringContainsString('#sitiochat', $exception->getMessage());
        self::assertSame('#sitiochat', $exception->getChannelLower());
    }

    #[Test]
    public function keepsTheDriverLevelCauseAsThePreviousException(): void
    {
        $cause = new RuntimeException('duplicate key');

        $exception = ChannelRegistrationConflictException::forChannel('#sitiochat', $cause);

        self::assertSame($cause, $exception->getPrevious());
    }
}
