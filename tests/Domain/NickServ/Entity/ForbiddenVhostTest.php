<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Entity;

use App\Domain\NickServ\Entity\ForbiddenVhost;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForbiddenVhost::class)]
final class ForbiddenVhostTest extends TestCase
{
    public function testCreateForbiddenVhost(): void
    {
        $forbidden = ForbiddenVhost::create('*.pirated.com', 123);

        self::assertSame('*.pirated.com', $forbidden->getPattern());
        self::assertSame(123, $forbidden->getCreatedByNickId());
        self::assertInstanceOf(DateTimeImmutable::class, $forbidden->getCreatedAt());
    }

    public function testCreateWithoutCreator(): void
    {
        $forbidden = ForbiddenVhost::create('badhost.*');

        self::assertSame('badhost.*', $forbidden->getPattern());
        self::assertNull($forbidden->getCreatedByNickId());
    }

    public function testMatchesExactPattern(): void
    {
        $forbidden = ForbiddenVhost::create('pirated.com');

        self::assertTrue($forbidden->matches('pirated.com'));
        self::assertFalse($forbidden->matches('other.com'));
    }

    public function testMatchesWildcardPattern(): void
    {
        $forbidden = ForbiddenVhost::create('*.pirated.com');

        self::assertTrue($forbidden->matches('sub.pirated.com'));
        self::assertTrue($forbidden->matches('deep.sub.pirated.com'));
        self::assertFalse($forbidden->matches('pirated.com'));
        self::assertFalse($forbidden->matches('other.com'));
    }

    public function testMatchesQuestionMarkWildcard(): void
    {
        $forbidden = ForbiddenVhost::create('bad?.com');

        self::assertTrue($forbidden->matches('bad1.com'));
        self::assertTrue($forbidden->matches('badX.com'));
        self::assertFalse($forbidden->matches('bad12.com'));
    }

    public function testMatchesIsCaseInsensitive(): void
    {
        $forbidden = ForbiddenVhost::create('*.Pirated.COM');

        self::assertTrue($forbidden->matches('sub.pirated.com'));
        self::assertTrue($forbidden->matches('SUB.PIRATED.COM'));
        self::assertTrue($forbidden->matches('Sub.Pirated.Com'));
    }

    public function testMatchesMultipleWildcards(): void
    {
        $forbidden = ForbiddenVhost::create('*.bad.*.com');

        self::assertTrue($forbidden->matches('sub.bad.host.com'));
        self::assertFalse($forbidden->matches('bad.com'));
    }

    public function testEmptyPatternThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pattern cannot be empty.');

        ForbiddenVhost::create('');
    }

    public function testWhitespaceOnlyPatternThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pattern cannot be empty.');

        ForbiddenVhost::create('   ');
    }

    public function testTooLongPatternThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pattern cannot exceed');

        $longPattern = str_repeat('a', ForbiddenVhost::MAX_PATTERN_LENGTH + 1);
        ForbiddenVhost::create($longPattern);
    }

    public function testPatternIsTrimmed(): void
    {
        $forbidden = ForbiddenVhost::create('  badhost.com  ');

        self::assertSame('badhost.com', $forbidden->getPattern());
    }
}
