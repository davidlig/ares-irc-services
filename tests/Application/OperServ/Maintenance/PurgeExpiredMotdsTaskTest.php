<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Maintenance;

use App\Application\OperServ\Maintenance\PurgeExpiredMotdsTask;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Application\Port\TranslationInterface;
use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function is_string;

#[CoversClass(PurgeExpiredMotdsTask::class)]
final class PurgeExpiredMotdsTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsOperservPurgeExpiredMotds(): void
    {
        $task = $this->createTask();

        self::assertSame('operserv.purge_expired_motds', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $task = $this->createTask(intervalSeconds: 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns365(): void
    {
        $task = $this->createTask();

        self::assertSame(365, $task->getOrder());
    }

    #[Test]
    public function runRemovesExpiredMotdsAndNotifiesDebug(): void
    {
        $expired = Motd::create('Expired', 'Bot1', 'PRIVMSG', null, new DateTimeImmutable('-1 hour'));
        $expired->recordShown();

        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository->expects(self::once())->method('findExpired')->willReturn([$expired]);
        $motdRepository->expects(self::once())->method('remove')->with($expired);

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('notify')
            ->with(self::stringContains('MOTD message'));

        $task = $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredMotds(): void
    {
        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository->expects(self::once())->method('findExpired')->willReturn([]);
        $motdRepository->expects(self::never())->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('notify');

        $task = $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier);
        $task->run();
    }

    private function createTask(
        ?MotdRepositoryInterface $motdRepository = null,
        ?ServiceDebugNotifierInterface $debugNotifier = null,
        int $intervalSeconds = 3600,
    ): PurgeExpiredMotdsTask {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            if ('motd.list.shown_count' === $id) {
                $count = $params['%count%'] ?? null;
                if (!is_string($count)) {
                    throw new LogicException('Expected a string MOTD count.');
                }

                return 'shown ' . $count . ' times';
            }

            if ('motd.debug.finalized' === $id) {
                $idValue = $params['%id%'] ?? null;
                $type = $params['%type%'] ?? null;
                $message = $params['%message%'] ?? null;
                $date = $params['%date%'] ?? null;
                $shownCount = $params['%shown_count%'] ?? null;
                if (!is_string($idValue) || !is_string($type) || !is_string($message) || !is_string($date) || !is_string($shownCount)) {
                    throw new LogicException('Expected string MOTD translation parameters.');
                }

                return 'MOTD message #' . $idValue . ' has ended: [' . $type . '] ' . $message . ' | ' . $date . ' | ' . $shownCount;
            }

            return $id;
        });

        return new PurgeExpiredMotdsTask(
            $motdRepository ?? $this->createStub(MotdRepositoryInterface::class),
            $debugNotifier ?? $this->createStub(ServiceDebugNotifierInterface::class),
            $translator,
            new NullLogger(),
            'en',
            'UTC',
            $intervalSeconds,
        );
    }
}
