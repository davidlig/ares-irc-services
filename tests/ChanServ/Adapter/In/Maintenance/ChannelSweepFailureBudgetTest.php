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
