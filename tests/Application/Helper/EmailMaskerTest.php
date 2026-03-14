<?php

declare(strict_types=1);

namespace App\Tests\Application\Helper;

use App\Application\Helper\EmailMasker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailMasker::class)]
final class EmailMaskerTest extends TestCase
{
    #[Test]
    public function maskShowsFirstTwoCharsAndDomain(): void
    {
        self::assertSame('da****@example.com', EmailMasker::mask('david@example.com'));
    }

    #[Test]
    public function maskSingleCharLocalPart(): void
    {
        self::assertSame('d****@x.com', EmailMasker::mask('d@x.com'));
    }

    #[Test]
    public function maskEmptyReturnsFallback(): void
    {
        self::assertSame('***@***', EmailMasker::mask(''));
    }

    #[Test]
    public function maskNoAtReturnsFallback(): void
    {
        self::assertSame('***@***', EmailMasker::mask('notanemail'));
    }

    #[Test]
    public function maskLocalPartEmptyReturnsFallback(): void
    {
        self::assertSame('***@***', EmailMasker::mask('@domain.com'));
    }

    #[Test]
    public function maskTrimsWhitespace(): void
    {
        self::assertSame('da****@example.com', EmailMasker::mask('  david@example.com  '));
    }

    #[Test]
    public function maskLocalPartExactlyTwoChars(): void
    {
        self::assertSame('ab****@x.com', EmailMasker::mask('ab@x.com'));
    }

    #[Test]
    public function maskMultibyteLocalPartUsesFirstTwoCharacters(): void
    {
        self::assertSame('ña****@test.com', EmailMasker::mask('ñaño@test.com'));
    }
}
