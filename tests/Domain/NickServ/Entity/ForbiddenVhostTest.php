<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Entity;

use App\Domain\NickServ\Entity\ForbiddenVhost;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ForbiddenVhost::class)]
final class ForbiddenVhostTest extends TestCase
{
    #[Test]
    public function createForbiddenVhost(): void
    {
        $forbidden = ForbiddenVhost::create('*.pirated.com', 123);

        self::assertSame('*.pirated.com', $forbidden->getPattern());
        self::assertSame(123, $forbidden->getCreatedByNickId());
        self::assertInstanceOf(DateTimeImmutable::class, $forbidden->getCreatedAt());
    }

    #[Test]
    public function createWithoutCreator(): void
    {
        $forbidden = ForbiddenVhost::create('badhost.*');

        self::assertSame('badhost.*', $forbidden->getPattern());
        self::assertNull($forbidden->getCreatedByNickId());
    }

    #[Test]
    public function matchesExactPattern(): void
    {
        $forbidden = ForbiddenVhost::create('pirated.com');

        self::assertTrue($forbidden->matches('pirated.com'));
        self::assertFalse($forbidden->matches('other.com'));
    }

    #[Test]
    public function matchesWildcardPattern(): void
    {
        $forbidden = ForbiddenVhost::create('*.pirated.com');

        self::assertTrue($forbidden->matches('sub.pirated.com'));
        self::assertTrue($forbidden->matches('deep.sub.pirated.com'));
        self::assertFalse($forbidden->matches('pirated.com'));
        self::assertFalse($forbidden->matches('other.com'));
    }

    #[Test]
    public function matchesQuestionMarkWildcard(): void
    {
        $forbidden = ForbiddenVhost::create('bad?.com');

        self::assertTrue($forbidden->matches('bad1.com'));
        self::assertTrue($forbidden->matches('badX.com'));
        self::assertFalse($forbidden->matches('bad12.com'));
    }

    #[Test]
    public function matchesIsCaseInsensitive(): void
    {
        $forbidden = ForbiddenVhost::create('*.Pirated.COM');

        self::assertTrue($forbidden->matches('sub.pirated.com'));
        self::assertTrue($forbidden->matches('SUB.PIRATED.COM'));
        self::assertTrue($forbidden->matches('Sub.Pirated.Com'));
    }

    #[Test]
    public function matchesMultipleWildcards(): void
    {
        $forbidden = ForbiddenVhost::create('*.bad.*.com');

        self::assertTrue($forbidden->matches('sub.bad.host.com'));
        self::assertFalse($forbidden->matches('bad.com'));
    }

    #[Test]
    public function emptyPatternThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pattern cannot be empty.');

        ForbiddenVhost::create('');
    }

    #[Test]
    public function whitespaceOnlyPatternThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pattern cannot be empty.');

        ForbiddenVhost::create('   ');
    }

    #[Test]
    public function tooLongPatternThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pattern cannot exceed');

        $longPattern = str_repeat('a', ForbiddenVhost::MAX_PATTERN_LENGTH + 1);
        ForbiddenVhost::create($longPattern);
    }

    #[Test]
    public function patternIsTrimmed(): void
    {
        $forbidden = ForbiddenVhost::create('  badhost.com  ');

        self::assertSame('badhost.com', $forbidden->getPattern());
    }

    #[Test]
    public function getIdReturnsValueAfterPersistence(): void
    {
        $forbidden = ForbiddenVhost::create('*.test.com', 1);

        $reflection = new ReflectionClass($forbidden);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($forbidden, 42);

        self::assertSame(42, $forbidden->getId());
    }
}
