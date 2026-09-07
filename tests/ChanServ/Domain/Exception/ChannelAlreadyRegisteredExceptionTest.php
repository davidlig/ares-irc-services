<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Exception;

use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelAlreadyRegisteredException::class)]
final class ChannelAlreadyRegisteredExceptionTest extends TestCase
{
    #[Test]
    public function forChannelCreatesExceptionWithMessage(): void
    {
        $e = ChannelAlreadyRegisteredException::forChannel('#test');

        self::assertStringContainsString('#test', $e->getMessage());
        self::assertStringContainsString('already registered', $e->getMessage());
    }
}
