<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\ChannelSweepFailureBudget;
use ArrayObject;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ChannelSweepFailureBudget::class)]
final class ChannelSweepFailureBudgetTest extends TestCase
{
    #[Test]
    public function theOldestExhaustedChannelIsForgottenSoMemoryStaysBounded(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        /** @var ArrayObject<int, string> $errors */
        $errors = new ArrayObject();
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });
        $budget = new ChannelSweepFailureBudget($logger, 'test.task', $entityManager);

        for ($channelId = 1; $channelId <= ChannelSweepFailureBudget::MAX_REMEMBERED_CHANNELS + 1; ++$channelId) {
            for ($attempt = 1; $attempt <= ChannelSweepFailureBudget::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS; ++$attempt) {
                $this->recordClosedFailure($budget, $channelId);
            }
        }
        $budget->finishSweep();

        $this->recordClosedFailure($budget, 2);
        $this->recordClosedFailure($budget, 1);

        $last = $errors->count() - 1;
        self::assertStringContainsString('#chan2 (id 2) again after it was skipped in an earlier sweep', $errors[$last - 1] ?? '');
        self::assertSame('Maintenance [test.task]: failed to drop channel #chan1 (id 1); the EntityManager is closed, stopping this batch.', $errors[$last]);
        self::assertFalse($budget->isSettled(1), 'the forgotten channel starts a fresh budget');
        self::assertTrue($budget->isSettled(2));
    }

    #[Test]
    public function anExhaustedChannelThatClosesTheEntityManagerAgainIsRememberedAsTheNewestOne(): void
    {
        $errors = self::messages();
        $budget = new ChannelSweepFailureBudget($this->errorLogger($errors), 'test.task', $this->closedEntityManager());
        $this->exhaust($budget, 1, ChannelSweepFailureBudget::MAX_REMEMBERED_CHANNELS);
        $budget->finishSweep();

        $this->recordClosedFailure($budget, 1);
        $budget->finishSweep();
        $this->exhaust($budget, ChannelSweepFailureBudget::MAX_REMEMBERED_CHANNELS + 1, ChannelSweepFailureBudget::MAX_REMEMBERED_CHANNELS + 1);
        $budget->finishSweep();

        $this->recordClosedFailure($budget, 1);
        $this->recordClosedFailure($budget, 2);

        $last = $errors->count() - 1;
        self::assertStringContainsString('#chan1 (id 1) again after it was skipped in an earlier sweep', $errors[$last - 1] ?? '');
        self::assertSame('Maintenance [test.task]: failed to drop channel #chan2 (id 2); the EntityManager is closed, stopping this batch.', $errors[$last]);
    }

    #[Test]
    public function anExhaustedChannelWithARowSpecificFailureIsRememberedAsTheNewestOne(): void
    {
        $open = new ArrayObject([false]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static fn (): bool => true === $open[0]);
        $errors = self::messages();
        $budget = new ChannelSweepFailureBudget($this->errorLogger($errors), 'test.task', $entityManager);
        $this->exhaust($budget, 1, ChannelSweepFailureBudget::MAX_REMEMBERED_CHANNELS);
        $budget->finishSweep();

        $open[0] = true;
        $budget->recordFailure(1, '#chan1', new RuntimeException('constraint violation'), 'drop channel');
        $budget->finishSweep();
        $open[0] = false;
        $this->exhaust($budget, ChannelSweepFailureBudget::MAX_REMEMBERED_CHANNELS + 1, ChannelSweepFailureBudget::MAX_REMEMBERED_CHANNELS + 1);
        $budget->finishSweep();

        $this->recordClosedFailure($budget, 1);

        self::assertStringContainsString('#chan1 (id 1) again after it was skipped in an earlier sweep', $errors[$errors->count() - 1] ?? '');
    }

    #[Test]
    public function aPostCommitFailureCompletesTheChannelWhenTheChangeWasCommitted(): void
    {
        $errors = self::messages();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $budget = new ChannelSweepFailureBudget($this->errorLogger($errors), 'test.task', $entityManager);

        $budget->recordFailureAfterPossibleCommit(1, '#gone', new RuntimeException('event failed'), 'drop channel', static fn (): bool => true, 'dropped channel', 'post-drop');
        $budget->recordFailureAfterPossibleCommit(2, '#kept', new RuntimeException('drop failed'), 'drop channel', static fn (): bool => false, 'dropped channel', 'post-drop');

        self::assertSame([
            'Maintenance [test.task]: dropped channel #gone (id 1), but a post-drop step failed.',
            'Maintenance [test.task]: failed to drop channel #kept (id 2); skipping it until the next sweep.',
        ], $errors->getArrayCopy());
        self::assertFalse($budget->isSettled(1));
        self::assertTrue($budget->isSettled(2));
    }

    private function exhaust(ChannelSweepFailureBudget $budget, int $fromId, int $toId): void
    {
        for ($channelId = $fromId; $channelId <= $toId; ++$channelId) {
            for ($attempt = 1; $attempt <= ChannelSweepFailureBudget::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS; ++$attempt) {
                $this->recordClosedFailure($budget, $channelId);
            }
        }
    }

    private function closedEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);

        return $entityManager;
    }

    /** @param ArrayObject<int, string> $errors */
    private function errorLogger(ArrayObject $errors): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($errors): void {
            $errors[] = $message;
        });

        return $logger;
    }

    /** @return ArrayObject<int, string> */
    private static function messages(): ArrayObject
    {
        return new ArrayObject();
    }

    private function recordClosedFailure(ChannelSweepFailureBudget $budget, int $channelId): void
    {
        try {
            $budget->recordFailure($channelId, '#chan' . $channelId, new RuntimeException('deadlock'), 'drop channel');
        } catch (RuntimeException) {
            return;
        }

        self::fail('a closed EntityManager rethrows the failure');
    }
}
