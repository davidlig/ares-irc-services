<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\ClosedEntityManagerRetryBudget;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ClosedEntityManagerRetryBudget::class)]
final class ClosedEntityManagerRetryBudgetTest extends TestCase
{
    private bool $databaseUp = true;

    /** @var list<string> */
    private array $probes = [];

    #[Test]
    public function aRowIsExhaustedAfterThreeConsecutiveFailuresWhileTheDatabaseAnswers(): void
    {
        $budget = new ClosedEntityManagerRetryBudget();
        $em = $this->entityManager();

        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertTrue($budget->exhaustedBy(7, $em));
        self::assertSame(['SELECT 1', 'SELECT 1', 'SELECT 1'], $this->probes);
    }

    #[Test]
    public function theCountRestartsAfterTheRowIsExhausted(): void
    {
        $budget = new ClosedEntityManagerRetryBudget();
        $em = $this->entityManager();
        $budget->exhaustedBy(7, $em);
        $budget->exhaustedBy(7, $em);
        $budget->exhaustedBy(7, $em);

        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertTrue($budget->exhaustedBy(7, $em));
    }

    #[Test]
    public function failuresDuringAnOutageNeitherCountNorExhaustTheRow(): void
    {
        $budget = new ClosedEntityManagerRetryBudget();
        $em = $this->entityManager();
        $budget->exhaustedBy(7, $em);
        $budget->exhaustedBy(7, $em);
        $this->databaseUp = false;

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::assertFalse($budget->exhaustedBy(7, $em));
        }

        $this->databaseUp = true;
        self::assertTrue($budget->exhaustedBy(7, $em));
    }

    #[Test]
    public function aFailureOfAnotherRowStartsItsOwnCount(): void
    {
        $budget = new ClosedEntityManagerRetryBudget();
        $em = $this->entityManager();
        $budget->exhaustedBy(7, $em);
        $budget->exhaustedBy(7, $em);

        self::assertFalse($budget->exhaustedBy(8, $em));
        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertTrue($budget->exhaustedBy(7, $em));
    }

    #[Test]
    public function resetDropsTheCountOfTheRow(): void
    {
        $budget = new ClosedEntityManagerRetryBudget();
        $em = $this->entityManager();
        $budget->exhaustedBy(7, $em);
        $budget->exhaustedBy(7, $em);

        $budget->reset();

        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertFalse($budget->exhaustedBy(7, $em));
        self::assertTrue($budget->exhaustedBy(7, $em));
    }

    private function entityManager(): EntityManagerInterface
    {
        $platform = $this->createStub(AbstractPlatform::class);
        $platform->method('getDummySelectSQL')->willReturn('SELECT 1');
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql): int {
            if (!$this->databaseUp) {
                throw new RuntimeException('connection refused');
            }
            $this->probes[] = $sql;

            return 1;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }
}
