<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\Entity;

use App\Domain\OperServ\Entity\Gline;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Gline::class)]
final class GlineTest extends TestCase
{
    #[Test]
    public function createWithAllParameters(): void
    {
        $expiresAt = new DateTimeImmutable('+1 day');
        $gline = Gline::create('*@192.168.*', 42, 'Spam bot', $expiresAt);

        self::assertSame('*@192.168.*', $gline->getMask());
        self::assertSame(42, $gline->getCreatorNickId());
        self::assertSame('Spam bot', $gline->getReason());
        self::assertSame($expiresAt, $gline->getExpiresAt());
        self::assertFalse($gline->isExpired());
        self::assertFalse($gline->isPermanent());
    }

    #[Test]
    public function createWithMinimalParameters(): void
    {
        $gline = Gline::create('*@*.badisp.com');

        self::assertSame('*@*.badisp.com', $gline->getMask());
        self::assertNull($gline->getCreatorNickId());
        self::assertNull($gline->getReason());
        self::assertNull($gline->getExpiresAt());
        self::assertFalse($gline->isExpired());
        self::assertTrue($gline->isPermanent());
    }

    #[Test]
    public function emptyMaskThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mask must be between 1 and');

        Gline::create('');
    }

    #[Test]
    public function maskExceedingMaxLengthThrowsException(): void
    {
        $longMask = str_repeat('a', 256) . '@host';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mask must be between 1 and');

        Gline::create($longMask);
    }

    #[Test]
    public function maskWithoutAtSymbolThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mask format');

        Gline::create('baduser');
    }

    #[Test]
    public function reasonExceedingMaxLengthThrowsException(): void
    {
        $longReason = str_repeat('a', 256);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reason cannot exceed');

        Gline::create('*@host', null, $longReason);
    }

    #[Test]
    public function emptyReasonBecomesNull(): void
    {
        $gline = Gline::create('*@host', null, '');

        self::assertNull($gline->getReason());
    }

    #[Test]
    public function isExpiredReturnsTrueForPastDate(): void
    {
        $pastDate = new DateTimeImmutable('-1 second');
        $gline = Gline::create('*@host', null, null, $pastDate);

        self::assertTrue($gline->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureDate(): void
    {
        $futureDate = new DateTimeImmutable('+1 hour');
        $gline = Gline::create('*@host', null, null, $futureDate);

        self::assertFalse($gline->isExpired());
    }

    #[Test]
    public function matchesReturnsTrueForMatchingMask(): void
    {
        $gline = Gline::create('*@192.168.*');

        self::assertTrue($gline->matches('user@192.168.1.1'));
        self::assertTrue($gline->matches('anyone@192.168.255.255'));
    }

    #[Test]
    public function matchesReturnsFalseForNonMatchingMask(): void
    {
        $gline = Gline::create('*@192.168.*');

        self::assertFalse($gline->matches('user@10.0.0.1'));
        self::assertFalse($gline->matches('user@192.169.1.1'));
    }

    #[Test]
    public function matchesIsCaseInsensitive(): void
    {
        $gline = Gline::create('BADUSER@*');

        self::assertTrue($gline->matches('baduser@host.com'));
        self::assertTrue($gline->matches('BadUser@HOST.COM'));
    }

    #[Test]
    public function updateReasonChangesReason(): void
    {
        $gline = Gline::create('*@host', null, 'Old reason');
        $gline->updateReason('New reason');

        self::assertSame('New reason', $gline->getReason());
    }

    #[Test]
    public function updateReasonToNullSetsNull(): void
    {
        $gline = Gline::create('*@host', null, 'Old reason');
        $gline->updateReason(null);

        self::assertNull($gline->getReason());
    }

    #[Test]
    public function updateExpiryChangesExpiry(): void
    {
        $gline = Gline::create('*@host');
        $newExpiry = new DateTimeImmutable('+2 days');
        $gline->updateExpiry($newExpiry);

        self::assertSame($newExpiry, $gline->getExpiresAt());
    }

    #[Test]
    public function updateExpiryToNullMakesPermanent(): void
    {
        $gline = Gline::create('*@host', null, null, new DateTimeImmutable('+1 day'));
        $gline->updateExpiry(null);

        self::assertNull($gline->getExpiresAt());
        self::assertTrue($gline->isPermanent());
    }

    #[Test]
    public function isValidMaskReturnsTrueForValidMasks(): void
    {
        self::assertTrue(Gline::isValidMask('*@*'));
        self::assertTrue(Gline::isValidMask('user@host'));
        self::assertTrue(Gline::isValidMask('*@192.168.*'));
        self::assertTrue(Gline::isValidMask('SomeNick')); // Nickname is now valid
    }

    #[Test]
    public function isValidMaskReturnsFalseForInvalidMasks(): void
    {
        self::assertFalse(Gline::isValidMask(''));
        // Masks with ! are not valid for GLINE
        self::assertFalse(Gline::isValidMask('nick!user@host'));
        self::assertFalse(Gline::isValidMask('bad!*@*.isp.com'));
    }

    #[Test]
    public function isSafeMaskReturnsTrueForSpecificEnoughMasks(): void
    {
        self::assertTrue(Gline::isSafeMask('user@host.com'));
        self::assertTrue(Gline::isSafeMask('*@192.168.1.1'));
        self::assertTrue(Gline::isSafeMask('*@host123.com'));
    }

    #[Test]
    public function isSafeMaskReturnsTrueForUserSpecificMasks(): void
    {
        // Masks with specific user (alphanumeric chars) are safe even with wildcard host
        self::assertTrue(Gline::isSafeMask('ares-859015@*'));
        self::assertTrue(Gline::isSafeMask('specific@*'));
        self::assertTrue(Gline::isSafeMask('user123@*'));
        self::assertTrue(Gline::isSafeMask('JohnDoe@*'));
        self::assertTrue(Gline::isSafeMask('bob@*'));
    }

    #[Test]
    public function isSafeMaskReturnsFalseForTooBroadMasks(): void
    {
        self::assertFalse(Gline::isSafeMask('*@*'));
        self::assertFalse(Gline::isSafeMask('*@abc'));
        self::assertFalse(Gline::isSafeMask('*@*.'));
        self::assertFalse(Gline::isSafeMask('nick!*@*'));
        self::assertFalse(Gline::isSafeMask('ares-486363!*@*'));
    }

    #[Test]
    public function isSafeMaskRequiresMinAlnumCharsInHost(): void
    {
        self::assertTrue(Gline::isSafeMask('*@abcd.com'));
        self::assertFalse(Gline::isSafeMask('*@abc'));
        self::assertFalse(Gline::isSafeMask('*@a.c'));
        self::assertTrue(Gline::isSafeMask('*@test'));
        self::assertTrue(Gline::isSafeMask('*@1234'));
    }

    #[Test]
    public function isGlobalMaskReturnsTrueForGlobalMasks(): void
    {
        self::assertTrue(Gline::isGlobalMask('*'));
        self::assertTrue(Gline::isGlobalMask('*!*@*'));
        self::assertTrue(Gline::isGlobalMask('*@*'));
        self::assertTrue(Gline::isGlobalMask('*!*@*'));
    }

    #[Test]
    public function isGlobalMaskReturnsFalseForSpecificMasks(): void
    {
        self::assertFalse(Gline::isGlobalMask('*@192.168.*'));
        self::assertFalse(Gline::isGlobalMask('user@host'));
        self::assertFalse(Gline::isGlobalMask('baduser@*'));
    }

    #[Test]
    public function isUserHostMaskReturnsTrueForUserHostFormat(): void
    {
        self::assertTrue(Gline::isUserHostMask('user@host'));
        self::assertTrue(Gline::isUserHostMask('*@192.168.*'));
        self::assertTrue(Gline::isUserHostMask('*@host123.com'));
    }

    #[Test]
    public function isUserHostMaskReturnsFalseForNicknames(): void
    {
        self::assertFalse(Gline::isUserHostMask('SomeNick'));
        self::assertFalse(Gline::isUserHostMask(''));
    }

    #[Test]
    public function isNicknameMaskReturnsTrueForNicknames(): void
    {
        self::assertTrue(Gline::isNicknameMask('SomeNick'));
        self::assertTrue(Gline::isNicknameMask('TestUser123'));
    }

    #[Test]
    public function isNicknameMaskReturnsFalseForUserHost(): void
    {
        self::assertFalse(Gline::isNicknameMask('user@host'));
        self::assertFalse(Gline::isNicknameMask(''));
        self::assertFalse(Gline::isNicknameMask('nick!user@host'));
    }

    #[Test]
    public function isGlobalMaskMatchesVariantsWithAsterisks(): void
    {
        self::assertTrue(Gline::isGlobalMask('*!*@*'));
        self::assertTrue(Gline::isGlobalMask('*!*@*'));
        self::assertTrue(Gline::isGlobalMask('*!*@**'));
        self::assertTrue(Gline::isGlobalMask('*!@*'));
    }

    #[Test]
    public function isSafeMaskReturnsFalseForMaskWithoutAt(): void
    {
        $result = Gline::isSafeMask('nomask');
        self::assertFalse($result);
    }

    #[Test]
    public function parseUserHostParsesSimpleMask(): void
    {
        $parts = Gline::parseUserHost('user@host.com');

        self::assertSame('user', $parts['user']);
        self::assertSame('host.com', $parts['host']);
    }

    #[Test]
    public function parseUserHostParsesWildcardMask(): void
    {
        $parts = Gline::parseUserHost('*@192.168.*');

        self::assertSame('*', $parts['user']);
        self::assertSame('192.168.*', $parts['host']);
    }

    #[Test]
    public function parseUserHostDefaultsToStarForMissingParts(): void
    {
        $parts = Gline::parseUserHost('@host');

        self::assertSame('*', $parts['user']);
        self::assertSame('host', $parts['host']);
    }

    #[Test]
    public function parseUserHostNoAtReturnsHost(): void
    {
        $parts = Gline::parseUserHost('hostonly');

        self::assertSame('*', $parts['user']);
        self::assertSame('hostonly', $parts['host']);
    }

    #[Test]
    public function constantsHaveExpectedValues(): void
    {
        self::assertSame(255, Gline::MAX_MASK_LENGTH);
        self::assertSame(255, Gline::MAX_REASON_LENGTH);
        self::assertSame(1000, Gline::MAX_ENTRIES);
        self::assertSame(4, Gline::MIN_ALNUM_CHARS);
    }

    #[Test]
    public function getIdReturnsIdAfterPersistence(): void
    {
        $gline = Gline::create('*@host');
        $ref = new ReflectionClass($gline);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($gline, 123);

        self::assertSame(123, $gline->getId());
    }

    #[Test]
    public function getCreatedAtReturnsDateTime(): void
    {
        $before = new DateTimeImmutable();
        $gline = Gline::create('*@host');
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $gline->getCreatedAt());
        self::assertLessThanOrEqual($after, $gline->getCreatedAt());
    }
}
