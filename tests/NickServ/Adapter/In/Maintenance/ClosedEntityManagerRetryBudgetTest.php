<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\ClosedEntityManagerRetryBudget;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function array_column;
use function array_key_last;
use function sprintf;
use function str_contains;

#[CoversClass(ClosedEntityManagerRetryBudget::class)]
final class ClosedEntityManagerRetryBudgetTest extends TestCase
{
    private const string STOP = 'stop';

    private const string EXHAUSTED = 'exhausted';

    private const string AGAIN = 'again';

    private bool $databaseUp = true;

    private bool $open = false;

    /** @var list<string> */
    private array $probes = [];

    /** @var list<array{message: string, exception: mixed}> */
    private array $logged = [];

    private RuntimeException $failure;

    /** @var list<int> ids passed to the skip callback, 0 when a failure did not skip */
    private array $skipped = [];

    #[Test]
    public function aRowIsSkippedAfterThreeConsecutiveFailuresWhileTheDatabaseAnswers(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();

        self::assertSame([self::STOP, self::STOP, self::EXHAUSTED], [$this->closedFailure($budget, 7, $em), $this->closedFailure($budget, 7, $em), $this->closedFailure($budget, 7, $em)]);
        self::assertSame([0, 0, 7], $this->skipped);
        self::assertSame(['SELECT 1', 'SELECT 1', 'SELECT 1'], $this->probes);
        self::assertSame([
            'Maintenance [test.task]: failed to touch row 7; the EntityManager is closed, stopping this batch.',
            'Maintenance [test.task]: failed to touch row 7; the EntityManager is closed, stopping this batch.',
            'Maintenance [test.task]: failed to touch row 7 with the EntityManager closed 3 times in a row; skipping it until the next sweep and stopping this batch.',
        ], array_column($this->logged, 'message'));
        self::assertSame($this->failure, $this->logged[0]['exception']);
    }

    #[Test]
    public function aSkippedRowThatFailsAgainInALaterSweepIsSkippedAtOnce(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();
        $this->exhaust($budget, 7, $em);

        self::assertSame(self::AGAIN, $this->closedFailure($budget, 7, $em));
        self::assertSame(self::AGAIN, $this->closedFailure($budget, 7, $em));
        self::assertSame(
            'Maintenance [test.task]: failed to touch row 7 again after it was skipped in an earlier sweep; skipping it until the next sweep and stopping this batch.',
            $this->logged[3]['message'],
        );
    }

    #[Test]
    public function aSkippedRowThatSucceedsGetsAFullBudgetAgain(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();
        $this->exhaust($budget, 7, $em);

        $budget->rowSettled(7);

        self::assertSame([self::STOP, self::STOP, self::EXHAUSTED], [$this->closedFailure($budget, 7, $em), $this->closedFailure($budget, 7, $em), $this->closedFailure($budget, 7, $em)]);
    }

    #[Test]
    public function aSkippedRowFailingDuringAnOutageIsNotSkipped(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();
        $this->exhaust($budget, 7, $em);
        $this->databaseUp = false;

        self::assertSame(self::STOP, $this->closedFailure($budget, 7, $em));
        self::assertSame(self::STOP, $this->closedFailure($budget, 7, $em));

        $this->databaseUp = true;
        self::assertSame(self::AGAIN, $this->closedFailure($budget, 7, $em));
    }

    #[Test]
    public function theOldestRememberedRowIsForgottenOnceTheMemoryIsFull(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();
        for ($row = 1; $row <= ClosedEntityManagerRetryBudget::MAX_REMEMBERED_ROWS + 1; ++$row) {
            $this->exhaust($budget, $row, $em);
        }

        self::assertSame(self::AGAIN, $this->closedFailure($budget, 2, $em));
        self::assertSame(self::STOP, $this->closedFailure($budget, 1, $em));
    }

    #[Test]
    public function failuresDuringAnOutageNeitherCountNorSkipTheRow(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();
        $this->closedFailure($budget, 7, $em);
        $this->closedFailure($budget, 7, $em);
        $this->databaseUp = false;

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::assertSame(self::STOP, $this->closedFailure($budget, 7, $em));
        }

        $this->databaseUp = true;
        self::assertSame(self::EXHAUSTED, $this->closedFailure($budget, 7, $em));
    }

    #[Test]
    public function aFailureOfAnotherRowStartsItsOwnCount(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();
        $this->closedFailure($budget, 7, $em);
        $this->closedFailure($budget, 7, $em);

        self::assertSame(self::STOP, $this->closedFailure($budget, 8, $em));
        self::assertSame(self::STOP, $this->closedFailure($budget, 7, $em));
        self::assertSame(self::STOP, $this->closedFailure($budget, 7, $em));
        self::assertSame(self::EXHAUSTED, $this->closedFailure($budget, 7, $em));
    }

    #[Test]
    public function aSettledRowDropsItsCount(): void
    {
        $budget = $this->budget();
        $em = $this->entityManager();
        $this->closedFailure($budget, 7, $em);
        $this->closedFailure($budget, 7, $em);

        $budget->rowSettled(7);

        self::assertSame([self::STOP, self::STOP, self::EXHAUSTED], [$this->closedFailure($budget, 7, $em), $this->closedFailure($budget, 7, $em), $this->closedFailure($budget, 7, $em)]);
    }

    #[Test]
    public function aFailureWithAnOpenOrNoEntityManagerIsLeftToTheCaller(): void
    {
        $budget = $this->budget();
        $this->open = true;

        $budget->rethrowWhenClosed(7, $this->entityManager(), $this->failure, 'failed to touch row 7', $this->skipRow(7));
        $budget->rethrowWhenClosed(7, null, $this->failure, 'failed to touch row 7', $this->skipRow(7));

        self::assertSame([], $this->logged);
        self::assertSame([], $this->probes);
        self::assertSame([], $this->skipped);
    }

    protected function setUp(): void
    {
        $this->failure = new RuntimeException('foreign key violation');
    }

    private function budget(): ClosedEntityManagerRetryBudget
    {
        $logger = new class($this->record(...)) extends AbstractLogger {
            /** @param Closure(string, mixed): void $record */
            public function __construct(private readonly Closure $record) {}

            /** @param array<mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                ($this->record)((string) $message, $context['exception'] ?? null);
            }
        };

        return new ClosedEntityManagerRetryBudget($logger, 'test.task');
    }

    private function record(string $message, mixed $exception): void
    {
        $this->logged[] = ['message' => $message, 'exception' => $exception];
    }

    private function exhaust(ClosedEntityManagerRetryBudget $budget, int $rowId, EntityManagerInterface $em): void
    {
        for ($attempt = 0; $attempt < ClosedEntityManagerRetryBudget::MAX_ATTEMPTS; ++$attempt) {
            $this->closedFailure($budget, $rowId, $em);
        }
    }

    /** Runs one closed-EntityManager failure and tells how the budget judged it. */
    private function closedFailure(ClosedEntityManagerRetryBudget $budget, int $rowId, EntityManagerInterface $em): string
    {
        $skippedBefore = $this->skipped;
        try {
            $budget->rethrowWhenClosed($rowId, $em, $this->failure, sprintf('failed to touch row %d', $rowId), $this->skipRow($rowId));
            self::fail('A closed EntityManager failure must be rethrown.');
        } catch (RuntimeException $e) {
            self::assertSame($this->failure, $e);
        }

        self::assertNotEmpty($this->logged);
        $message = $this->logged[array_key_last($this->logged)]['message'];
        if ($skippedBefore === $this->skipped) {
            $this->skipped[] = 0;
            self::assertStringEndsWith('the EntityManager is closed, stopping this batch.', $message);

            return self::STOP;
        }

        return str_contains($message, ' again after ') ? self::AGAIN : self::EXHAUSTED;
    }

    private function skipRow(int $rowId): Closure
    {
        return function () use ($rowId): void {
            $this->skipped[] = $rowId;
        };
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
        $em->method('isOpen')->willReturnCallback(fn (): bool => $this->open);
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }
}
