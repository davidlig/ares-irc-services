<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Persistence;

use App\Irc\Adapter\Out\Persistence\ClosedEntityManagerReset;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ClosedEntityManagerReset::class)]
final class ClosedEntityManagerResetTest extends TestCase
{
    #[Test]
    public function withoutARegistryNothingIsChecked(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        new ClosedEntityManagerReset()->resetIfClosed($logger, 'IRC');
    }

    #[Test]
    public function anOpenEntityManagerIsLeftAlone(): void
    {
        $registry = $this->registry(open: true);
        $registry->expects(self::never())->method('resetManager');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        new ClosedEntityManagerReset($registry)->resetIfClosed($logger, 'IRC');
    }

    #[Test]
    public function aClosedEntityManagerIsResetWithAWarning(): void
    {
        $registry = $this->registry(open: false);
        $registry->expects(self::once())->method('resetManager');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('IRC: the EntityManager is closed, resetting it', ['line' => 1]);
        $logger->expects(self::never())->method('error');

        new ClosedEntityManagerReset($registry)->resetIfClosed($logger, 'IRC', ['line' => 1]);
    }

    #[Test]
    public function aFailingResetIsLoggedAndNotThrown(): void
    {
        $failure = new \RuntimeException('reset failed');
        $registry = $this->registry(open: false);
        $registry->expects(self::once())->method('resetManager')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::once())->method('error')->with(
            'Maintenance: resetting the closed EntityManager failed',
            ['after_task' => null, 'exception' => $failure],
        );

        new ClosedEntityManagerReset($registry)->resetIfClosed($logger, 'Maintenance', ['after_task' => null]);
    }

    #[Test]
    public function aManagerThatIsNotAnEntityManagerIsNeverReset(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $registry->expects(self::never())->method('resetManager');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        new ClosedEntityManagerReset($registry)->resetIfClosed($logger, 'IRC');
    }

    private function registry(bool $open): ManagerRegistry&MockObject
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn($open);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($entityManager);

        return $registry;
    }
}
