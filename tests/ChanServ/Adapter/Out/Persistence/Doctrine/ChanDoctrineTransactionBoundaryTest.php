<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Application\Port\TransactionManagerInterface;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChanDoctrineTransactionBoundary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanDoctrineTransactionBoundary::class)]
final class ChanDoctrineTransactionBoundaryTest extends TestCase
{
    #[Test]
    public function delegatesTransactionAndReturnsItsResult(): void
    {
        $operation = static fn (): string => 'result';
        $transactionManager = $this->createMock(TransactionManagerInterface::class);
        $transactionManager->expects(self::once())->method('transactional')->with($operation)->willReturn('result');

        self::assertSame('result', new ChanDoctrineTransactionBoundary($transactionManager)->transactional($operation));
    }
}
