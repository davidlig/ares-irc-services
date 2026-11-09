<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\InMemory;

use App\ChanServ\Adapter\Out\InMemory\FounderChangeTokenRegistry;
use App\NickServ\Adapter\In\Maintenance\PruneMemoryRegistriesTask;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(FounderChangeTokenRegistry::class)]
final class FounderChangeTokenRegistryTest extends TestCase
{
    #[Test]
    public function maintenancePrunesExpiredTokensAndStaleRequestsOnly(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $registry = new FounderChangeTokenRegistry(static function () use (&$now): DateTimeImmutable { return $now; });
        $registry->recordRequest(1);
        $registry->store(1, 10, 'expired', $now->modify('+1 hour'));
        $now = $now->modify('+25 hours');
        $registry->recordRequest(2);
        $registry->store(2, 20, 'valid', $now->modify('+1 hour'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('pruned 2 stale'));
        new PruneMemoryRegistriesTask([$registry], $logger, 60)->run();

        self::assertNull($registry->getLastRequestAt(1));
        self::assertSame($now, $registry->getLastRequestAt(2));
        self::assertNull($registry->consume(1, 'expired'));
        self::assertSame(20, $registry->consume(2, 'valid'));
        self::assertSame(0, $registry->prune());
    }

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
        $expires = new DateTimeImmutable()->modify('+1 hour');
        $registry->store(10, 99, 'secret-token', $expires);

        self::assertSame(99, $registry->consume(10, 'secret-token'));
        self::assertNull($registry->consume(10, 'secret-token'));
    }

    #[Test]
    public function consumeReturnsNullWhenTokenWrong(): void
    {
        $registry = new FounderChangeTokenRegistry();
        $registry->store(10, 99, 'secret', new DateTimeImmutable()->modify('+1 hour'));

        self::assertNull($registry->consume(10, 'wrong'));
    }

    #[Test]
    public function consumeReturnsNullWhenExpired(): void
    {
        $registry = new FounderChangeTokenRegistry();
        $registry->store(10, 99, 'secret', new DateTimeImmutable()->modify('-1 second'));

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
