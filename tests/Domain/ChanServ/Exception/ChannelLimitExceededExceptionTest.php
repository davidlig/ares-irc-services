<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Exception;

use App\Domain\ChanServ\Exception\ChannelLimitExceededException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelLimitExceededException::class)]
final class ChannelLimitExceededExceptionTest extends TestCase
{
    #[Test]
    public function forNicknameCreatesExceptionWithNickAndMax(): void
    {
        $e = ChannelLimitExceededException::forNickname('Nick', 5);

        self::assertStringContainsString('Nick', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
    }
}
