<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Exception;

use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelNotRegisteredException::class)]
final class ChannelNotRegisteredExceptionTest extends TestCase
{
    #[Test]
    public function forChannelCreatesExceptionWithChannelName(): void
    {
        $e = ChannelNotRegisteredException::forChannel('#test');

        self::assertStringContainsString('#test', $e->getMessage());
        self::assertSame('#test', $e->getChannelName());
    }
}
