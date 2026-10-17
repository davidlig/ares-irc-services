<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Persistence\Doctrine;

use App\Irc\Adapter\Out\Persistence\Doctrine\EntityManagerRecovery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(EntityManagerRecovery::class)]
final class EntityManagerRecoveryTest extends TestCase
{
    #[Test]
    public function resetsTheRegistryWhenTheManagerIsClosed(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects(self::once())->method('resetManager');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        new EntityManagerRecovery($registry, $logger)->recover();
    }

    #[Test]
    public function leavesAnOpenManagerUntouched(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects(self::never())->method('resetManager');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        new EntityManagerRecovery($registry, $logger)->recover();
    }

    #[Test]
    public function leavesANonEntityManagerUntouched(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $registry->expects(self::never())->method('resetManager');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        new EntityManagerRecovery($registry, $logger)->recover();
    }

    #[Test]
    public function defaultsToANullLogger(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        $reset = false;
        $registry
            ->expects(self::once())
            ->method('resetManager')
            ->willReturnCallback(function () use (&$reset): EntityManagerInterface {
                $reset = true;

                return $this->createStub(EntityManagerInterface::class);
            });

        new EntityManagerRecovery($registry)->recover();

        self::assertTrue($reset);
    }
}
