<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Irc\Adapter\In\Maintenance\MaintenanceScheduler;
use App\Irc\Adapter\Out\Persistence\ClosedEntityManagerReset;
use App\Irc\Adapter\Runtime\IRCClientFactory;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A maintenance task whose database error closed the EntityManager must not make every later
 * Doctrine-backed task fail until restart: the scheduler resets the closed manager, and because the
 * default EntityManager service is lazy, the reference a later task already holds is reopened in place.
 */
#[CoversNothing]
final class MaintenanceEntityManagerResetWiringTest extends KernelTestCase
{
    #[Test]
    public function theWiredSchedulerReceivesTheDoctrineRegistry(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $scheduler = $container->get(MaintenanceScheduler::class);
        self::assertInstanceOf(MaintenanceScheduler::class, $scheduler);

        self::assertSame($container->get('doctrine'), $this->registryOf(
            new \ReflectionProperty(MaintenanceScheduler::class, 'entityManagerReset')->getValue($scheduler),
        ));
    }

    /** A closed EntityManager is also reset before every incoming IRC line, not only per maintenance tick. */
    #[Test]
    public function theWiredIrcClientFactoryReceivesTheDoctrineRegistry(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $factory = $container->get(IRCClientFactory::class);
        self::assertInstanceOf(IRCClientFactory::class, $factory);

        self::assertSame($container->get('doctrine'), $this->registryOf(
            new \ReflectionProperty(IRCClientFactory::class, 'entityManagerReset')->getValue($factory),
        ));
    }

    #[Test]
    public function aLaterTaskHoldingTheInjectedEntityManagerSeesItReopened(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $registry = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $observed = new MaintenanceEntityManagerResetObservation();

        $closingTask = new MaintenanceEntityManagerResetTask('test.closing', 100, static function () use ($entityManager): void {
            $entityManager->close();

            throw new \RuntimeException('database error');
        });
        $laterTask = new MaintenanceEntityManagerResetTask('test.later', 200, static function () use ($entityManager, $observed): void {
            $observed->openInLaterTask = $entityManager->isOpen();
        });

        new MaintenanceScheduler([$closingTask, $laterTask], new NullLogger(), new ClosedEntityManagerReset($registry))->tick();

        self::assertTrue($observed->openInLaterTask);
        self::assertTrue($entityManager->isOpen());
    }

    private function registryOf(mixed $reset): mixed
    {
        self::assertInstanceOf(ClosedEntityManagerReset::class, $reset);

        return new \ReflectionProperty(ClosedEntityManagerReset::class, 'managerRegistry')->getValue($reset);
    }
}

final class MaintenanceEntityManagerResetObservation
{
    public ?bool $openInLaterTask = null;
}

final readonly class MaintenanceEntityManagerResetTask implements MaintenanceTaskInterface
{
    /** @param Closure(): void $work */
    public function __construct(
        private string $name,
        private int $order,
        private \Closure $work,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getIntervalSeconds(): int
    {
        return 0;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function run(): void
    {
        ($this->work)();
    }
}
