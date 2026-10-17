<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Irc\Adapter\Out\Persistence\Doctrine\EntityManagerRecovery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Doctrine closes an EntityManager for good when a flush fails. Recovery leans on the
 * fact that the injected EntityManager service is lazy: resetting the registry must
 * leave the very same instance every repository already holds usable again. If the
 * service ever stops being lazy, this test fails before a network failure does.
 */
#[CoversNothing]
final class EntityManagerRecoveryIntegrationTest extends KernelTestCase
{
    #[Test]
    public function aHeldReferenceIsUsableAgainAfterRecovery(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $held = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $held);
        self::assertTrue($held->isOpen());

        $held->close();
        self::assertFalse($held->isOpen());

        $recovery = $container->get(EntityManagerRecovery::class);
        self::assertInstanceOf(EntityManagerRecovery::class, $recovery);
        $recovery->recover();

        // The same object a repository already references works again, and it is still
        // the container's EntityManager.
        self::assertTrue($held->isOpen());
        self::assertSame($held, $container->get(EntityManagerInterface::class));
    }

    #[Test]
    public function recoveryIsAHarmlessNoOpOnAHealthyManager(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $held = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $held);
        self::assertTrue($held->isOpen());

        $recovery = $container->get(EntityManagerRecovery::class);
        self::assertInstanceOf(EntityManagerRecovery::class, $recovery);
        $recovery->recover();

        self::assertTrue($held->isOpen());
        self::assertSame($held, $container->get(EntityManagerInterface::class));
    }
}
