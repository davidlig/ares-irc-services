<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ;

use App\Application\ChanServ\FounderChangeTokenRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FounderChangeTokenRegistry::class)]
final class FounderChangeTokenRegistryTest extends TestCase
{
    #[Test]
    public function consumeReturnsNullWhenNoEntry(): void
    {
        $registry = new FounderChangeTokenRegistry();

        self::assertNull($registry->consume(1, 'any-token'));
    }

    #[Test]
    public function storeAndConsumeReturnsNewFounderNickId(): void
    {
        $registry = new FounderChangeTokenRegistry();
        $expires = (new DateTimeImmutable())->modify('+1 hour');
        $registry->store(10, 99, 'secret-token', $expires);

        self::assertSame(99, $registry->consume(10, 'secret-token'));
        self::assertNull($registry->consume(10, 'secret-token'));
    }

    #[Test]
    public function consumeReturnsNullWhenTokenWrong(): void
    {
        $registry = new FounderChangeTokenRegistry();
        $registry->store(10, 99, 'secret', (new DateTimeImmutable())->modify('+1 hour'));

        self::assertNull($registry->consume(10, 'wrong'));
    }

    #[Test]
    public function consumeReturnsNullWhenExpired(): void
    {
        $registry = new FounderChangeTokenRegistry();
        $registry->store(10, 99, 'secret', (new DateTimeImmutable())->modify('-1 second'));

        self::assertNull($registry->consume(10, 'secret'));
    }

    #[Test]
    public function getLastRequestAtAndRecordRequest(): void
    {
        $registry = new FounderChangeTokenRegistry();
        self::assertNull($registry->getLastRequestAt(5));

        $registry->recordRequest(5);
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastRequestAt(5));
    }
}
