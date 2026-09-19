<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\OperServ\Adapter\In\Maintenance\PurgeExpiredMotdsTask;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        $expired = new MotdEntry(1, 'Expired', 'Bot1', MessageDelivery::Interactive, true, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 1);

        $motdRepository = $this->createMock(MotdRepository::class);
        $motdRepository->expects(self::once())->method('findExpiredAt')->willReturn([$expired]);
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
        $motdRepository = $this->createMock(MotdRepository::class);
        $motdRepository->expects(self::once())->method('findExpiredAt')->willReturn([]);
        $motdRepository->expects(self::never())->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('notify');

        $task = $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier);
        $task->run();
    }

    private function createTask(
        ?MotdRepository $motdRepository = null,
        ?ServiceDebugNotifierInterface $debugNotifier = null,
        int $intervalSeconds = 3600,
    ): PurgeExpiredMotdsTask {
        $translator = $this->createStub(TranslatorInterface::class);
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
            $motdRepository ?? $this->createStub(MotdRepository::class),
            $debugNotifier ?? $this->createStub(ServiceDebugNotifierInterface::class),
            $translator,
            new NullLogger(),
            'en',
            'UTC',
            $intervalSeconds,
        );
    }
}
