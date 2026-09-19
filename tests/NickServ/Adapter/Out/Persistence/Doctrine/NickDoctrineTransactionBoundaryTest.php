<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\NickDoctrineTransactionBoundary;
use App\Shared\Application\Port\TransactionManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickDoctrineTransactionBoundary::class)]
final class NickDoctrineTransactionBoundaryTest extends TestCase
{
    #[Test]
    public function delegatesTransactionAndReturnsItsResult(): void
    {
        $operation = static fn (): string => 'result';
        $transactionManager = $this->createMock(TransactionManagerInterface::class);
        $transactionManager->expects(self::once())->method('transactional')->with($operation)->willReturn('result');

        self::assertSame('result', new NickDoctrineTransactionBoundary($transactionManager)->transactional($operation));
    }
}
