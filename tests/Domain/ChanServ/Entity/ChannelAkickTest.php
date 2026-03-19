<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Entity;

use App\Domain\ChanServ\Entity\ChannelAkick;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ChannelAkick::class)]
final class ChannelAkickTest extends TestCase
{
    #[Test]
    public function createSetsAllProperties(): void
    {
        $expiresAt = new DateTimeImmutable('+7 days');
        $akick = ChannelAkick::create(1, 2, '*!*@*.isp.com', 'Spammer', $expiresAt);

        self::assertSame(1, $akick->getChannelId());
        self::assertSame(2, $akick->getCreatorNickId());
        self::assertSame('*!*@*.isp.com', $akick->getMask());
        self::assertSame('Spammer', $akick->getReason());
        self::assertSame($expiresAt, $akick->getExpiresAt());
        self::assertFalse($akick->isExpired());
    }

    #[Test]
    public function createWithNullReasonAndExpiry(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@host.com');

        self::assertNull($akick->getReason());
        self::assertNull($akick->getExpiresAt());
        self::assertFalse($akick->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueWhenExpired(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@host.com', null, new DateTimeImmutable('-1 day'));

        self::assertTrue($akick->isExpired());
    }

    #[Test]
    public function matchesWorksCorrectly(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@*.isp.com');

        self::assertTrue($akick->matches('Nick!user@host.isp.com'));
        self::assertTrue($akick->matches('NICK!user@HOST.ISP.COM'));
        self::assertFalse($akick->matches('Nick!user@other.com'));
    }

    #[Test]
    public function matchesWithWildcards(): void
    {
        $akick = ChannelAkick::create(1, 2, 'Nick!*@*');

        self::assertTrue($akick->matches('Nick!user@host'));
        self::assertTrue($akick->matches('Nick!any@any'));
        self::assertFalse($akick->matches('Other!user@host'));
    }

    #[Test]
    public function updateReason(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@host.com', 'Old reason');

        $akick->updateReason('New reason');

        self::assertSame('New reason', $akick->getReason());
    }

    #[Test]
    public function updateExpiry(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@host.com');
        $newExpiry = new DateTimeImmutable('+30 days');

        $akick->updateExpiry($newExpiry);

        self::assertSame($newExpiry, $akick->getExpiresAt());
    }

    #[Test]
    public function isValidMaskRejectsInvalidFormats(): void
    {
        self::assertTrue(ChannelAkick::isValidMask('*!*@host.com'));
        self::assertTrue(ChannelAkick::isValidMask('Nick!user@host.com'));
        self::assertTrue(ChannelAkick::isValidMask('*!*@*'));

        self::assertFalse(ChannelAkick::isValidMask(''));
        self::assertFalse(ChannelAkick::isValidMask('no@host'));
        self::assertFalse(ChannelAkick::isValidMask('nick!user'));
    }

    #[Test]
    public function invalidMaskThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ChannelAkick::create(1, 2, 'invalid');
    }

    #[Test]
    public function maskTooLongThrowsException(): void
    {
        $longMask = str_repeat('a', 256) . '!user@host.com';

        $this->expectException(InvalidArgumentException::class);
        ChannelAkick::create(1, 2, $longMask);
    }

    #[Test]
    public function reasonTooLongThrowsException(): void
    {
        $longReason = str_repeat('a', 256);

        $this->expectException(InvalidArgumentException::class);
        ChannelAkick::create(1, 2, '*!*@host.com', $longReason);
    }

    #[Test]
    public function getCreatedAtReturnsSetTime(): void
    {
        $before = new DateTimeImmutable();
        usleep(1000);
        $akick = ChannelAkick::create(1, 2, '*!*@host.com');
        usleep(1000);
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $akick->getCreatedAt());
        self::assertLessThanOrEqual($after, $akick->getCreatedAt());
    }

    #[Test]
    public function getIdReturnsIdAfterReflectionSet(): void
    {
        $akick = ChannelAkick::create(1, 2, '*!*@host.com');

        $reflection = new ReflectionClass($akick);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($akick, 42);

        self::assertSame(42, $akick->getId());
    }

    #[Test]
    public function isSafeMaskRejectsTooBroadMasks(): void
    {
        self::assertFalse(ChannelAkick::isSafeMask('*!*@*'), 'No alphanumeric chars');
        self::assertFalse(ChannelAkick::isSafeMask('*!*@*.*'), 'Only wildcards and dots');
        self::assertFalse(ChannelAkick::isSafeMask('*!*@*.com'), 'Only 3 chars: com');
        self::assertFalse(ChannelAkick::isSafeMask('*!*@*.net'), 'Only 3 chars: net');
        self::assertFalse(ChannelAkick::isSafeMask('*!*@a.b'), 'Only 1 char: a');
        self::assertFalse(ChannelAkick::isSafeMask('*!*@*.*.*'), 'No alphanumeric chars');
    }

    #[Test]
    public function isSafeMaskAcceptsSpecificEnoughMasks(): void
    {
        self::assertTrue(ChannelAkick::isSafeMask('*!*@*.isp.com'), '6 chars: ispcom');
        self::assertTrue(ChannelAkick::isSafeMask('*!user@*'), '4 chars: user');
        self::assertTrue(ChannelAkick::isSafeMask('nick!*@*'), '4 chars: nick');
        self::assertTrue(ChannelAkick::isSafeMask('*!*@host'), '4 chars: host');
        self::assertTrue(ChannelAkick::isSafeMask('*!*@host.isp.com'), '10 chars: hostispcom');
        self::assertTrue(ChannelAkick::isSafeMask('nick!user@host.com'), '15 chars');
        self::assertTrue(ChannelAkick::isSafeMask('*!*@abcd'), '4 chars: abcd');
    }

    #[Test]
    public function isSafeMaskBoundaryCases(): void
    {
        self::assertFalse(ChannelAkick::isSafeMask('*!*@abc'), '3 chars: abc (below minimum)');
        self::assertTrue(ChannelAkick::isSafeMask('*!*@abcd'), '4 chars: abcd (at minimum)');
        self::assertTrue(ChannelAkick::isSafeMask('*!*@abcde'), '5 chars: abcde (above minimum)');
        self::assertFalse(ChannelAkick::isSafeMask('*!*@*abc*'), '3 chars: abc (below minimum)');
    }
}
