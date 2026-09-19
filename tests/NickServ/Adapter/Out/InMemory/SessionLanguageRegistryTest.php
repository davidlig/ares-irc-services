<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionLanguageRegistry::class)]
final class SessionLanguageRegistryTest extends TestCase
{
    #[Test]
    public function findReturnsNullInitially(): void
    {
        $registry = new SessionLanguageRegistry();
        self::assertNull($registry->find('001ABC'));
    }

    #[Test]
    public function registerAndFindReturnsLanguage(): void
    {
        $registry = new SessionLanguageRegistry();
        $registry->register('001ABC', 'es');
        self::assertSame('es', $registry->find('001ABC'));
    }

    #[Test]
    public function removeClearsEntry(): void
    {
        $registry = new SessionLanguageRegistry();
        $registry->register('001ABC', 'es');
        $registry->remove('001ABC');
        self::assertNull($registry->find('001ABC'));
    }

    #[Test]
    public function pruneDisconnectedRemovesUidsNotConnected(): void
    {
        $registry = new SessionLanguageRegistry();
        $registry->register('001A', 'es');
        $registry->register('001B', 'fr');
        $registry->register('001C', 'de');

        $removed = $registry->pruneDisconnected(static fn (string $uid): bool => '001B' !== $uid);

        self::assertSame(1, $removed);
        self::assertSame('es', $registry->find('001A'));
        self::assertNull($registry->find('001B'));
        self::assertSame('de', $registry->find('001C'));
    }

    #[Test]
    public function pruneDisconnectedReturnsZeroWhenAllValid(): void
    {
        $registry = new SessionLanguageRegistry();
        $registry->register('001A', 'es');
        $removed = $registry->pruneDisconnected(static fn (string $uid): bool => '001A' === $uid);
        self::assertSame(0, $removed);
    }
}
