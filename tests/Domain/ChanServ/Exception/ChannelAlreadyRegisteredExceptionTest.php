<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Exception;

use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
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
