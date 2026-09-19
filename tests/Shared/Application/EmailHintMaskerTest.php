<?php

declare(strict_types=1);

namespace App\Tests\Shared\Application;

use App\Shared\Application\EmailHintMasker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailHintMasker::class)]
final class EmailHintMaskerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function emailHints(): iterable
    {
        yield 'multi-label domain' => ['colegio@davidlig.com.es', 'co****@d****.***'];
        yield 'single-character local part and domain' => ['a@x.com', 'a****@x****.***'];
        yield 'two-character local part' => ['ab@x.com', 'ab****@x****.***'];
        yield 'Unicode local part and domain' => ['ñaño@éxample.com', 'ña****@é****.***'];
        yield 'outer whitespace' => ['  david@example.com  ', 'da****@e****.***'];
        yield 'empty address' => ['', '***@***'];
        yield 'missing at sign' => ['notanemail', '***@***'];
        yield 'empty local part' => ['@example.com', '***@***'];
        yield 'empty domain' => ['user@', '***@***'];
        yield 'domain without suffix' => ['user@example', '***@***'];
        yield 'empty domain label' => ['user@example..com', '***@***'];
        yield 'multiple at signs' => ['user@@example.com', '***@***'];
        yield 'internal whitespace' => ['user@exam ple.com', '***@***'];
        yield 'invalid UTF-8' => ["user@\xff.com", '***@***'];
    }

    #[Test]
    #[DataProvider('emailHints')]
    public function masksBothLocalPartAndDomain(string $email, string $expected): void
    {
        self::assertSame($expected, EmailHintMasker::mask($email));
    }
}
