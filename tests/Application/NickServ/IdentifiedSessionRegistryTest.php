<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\IdentifiedSessionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifiedSessionRegistry::class)]
final class IdentifiedSessionRegistryTest extends TestCase
{
    #[Test]
    public function findNickReturnsNullInitially(): void
    {
        $registry = new IdentifiedSessionRegistry();
        self::assertNull($registry->findNick('001ABC'));
    }

    #[Test]
    public function registerAndFindNickReturnsRegisteredNick(): void
    {
        $registry = new IdentifiedSessionRegistry();
        $registry->register('001ABC', 'MyNick');
        self::assertSame('MyNick', $registry->findNick('001ABC'));
    }

    #[Test]
    public function removeClearsEntry(): void
    {
        $registry = new IdentifiedSessionRegistry();
        $registry->register('001ABC', 'MyNick');
        $registry->remove('001ABC');
        self::assertNull($registry->findNick('001ABC'));
    }

    #[Test]
    public function pruneSessionsNotInRemovesUidsNotInList(): void
    {
        $registry = new IdentifiedSessionRegistry();
        $registry->register('001A', 'Nick1');
        $registry->register('001B', 'Nick2');
        $registry->register('001C', 'Nick3');

        $removed = $registry->pruneSessionsNotIn(['001A', '001C']);

        self::assertSame(1, $removed);
        self::assertSame('Nick1', $registry->findNick('001A'));
        self::assertNull($registry->findNick('001B'));
        self::assertSame('Nick3', $registry->findNick('001C'));
    }

    #[Test]
    public function pruneSessionsNotInReturnsZeroWhenAllValid(): void
    {
        $registry = new IdentifiedSessionRegistry();
        $registry->register('001A', 'Nick1');
        $removed = $registry->pruneSessionsNotIn(['001A']);
        self::assertSame(0, $removed);
    }
}
