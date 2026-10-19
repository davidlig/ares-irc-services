<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Domain\Entity;

use App\NickServ\Domain\Entity\BlockedEmailDomain;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BlockedEmailDomain::class)]
final class BlockedEmailDomainTest extends TestCase
{
    #[Test]
    public function normalizesWhitespaceAndCase(): void
    {
        self::assertSame('example.com', BlockedEmailDomain::normalize('  ExAmPle.CoM  '));
        self::assertSame('', BlockedEmailDomain::normalize('   '));
    }

    #[Test]
    public function storesTheNormalizedDomainAndCreationTime(): void
    {
        $createdAt = new DateTimeImmutable('2026-10-19 12:00:00 UTC');

        $domain = new BlockedEmailDomain('  EXAMPLE.com ', $createdAt);

        self::assertSame('example.com', $domain->getDomain());
        self::assertSame($createdAt, $domain->getCreatedAt());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $domain = new BlockedEmailDomain('example.com', new DateTimeImmutable());

        $reflection = new ReflectionClass($domain);
        $reflection->getProperty('id')->setValue($domain, 42);

        self::assertSame(42, $domain->getId());
    }

    /** @return iterable<string, array{string}> */
    public static function validDomains(): iterable
    {
        yield 'plain domain' => ['example.com'];
        yield 'subdomain' => ['mail.example.co.uk'];
        yield 'mixed case' => ['Example.COM'];
        yield 'surrounding whitespace' => [' example.com '];
    }

    #[Test]
    #[DataProvider('validDomains')]
    public function acceptsPlausibleEmailDomains(string $domain): void
    {
        self::assertTrue(BlockedEmailDomain::isValid(BlockedEmailDomain::normalize($domain)));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDomains(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no dot' => ['localhost'];
        yield 'at sign' => ['user@example.com'];
        yield 'leading dot' => ['.example.com'];
        yield 'trailing dot' => ['example.com.'];
        yield 'inner whitespace' => ['example .com'];
        yield 'too long' => [str_repeat('a', 250) . '.com'];
    }

    #[Test]
    #[DataProvider('invalidDomains')]
    public function rejectsImplausibleEmailDomains(string $domain): void
    {
        self::assertFalse(BlockedEmailDomain::isValid($domain));
    }

    #[Test]
    public function rejectsInvalidDomainsWhenConstructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockedEmailDomain('not-a-domain', new DateTimeImmutable());
    }
}
