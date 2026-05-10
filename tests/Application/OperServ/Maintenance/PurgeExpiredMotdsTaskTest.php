<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Maintenance;

use App\Application\OperServ\Maintenance\PurgeExpiredMotdsTask;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            if ('motd.list.shown_count' === $id) {
                return 'shown ' . $params['%count%'] . ' times';
            }

            if ('motd.debug.finalized' === $id) {
                return 'MOTD message #' . $params['%id%'] . ' has ended: [' . $params['%type%'] . '] ' . $params['%message%'] . ' | ' . $params['%date%'] . ' | ' . $params['%shown_count%'];
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
