<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance;

use App\Application\NickServ\Maintenance\UnsuspendExpiredSuspensionsTask;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(UnsuspendExpiredSuspensionsTask::class)]
final class UnsuspendExpiredSuspensionsTaskTest extends TestCase
{
    private const string SERVER_NAME = 'test-server.example.com';

    #[Test]
    public function getNameReturnsNickservUnsuspendExpiredSuspensions(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame('nickserv.unsuspend_expired_suspensions', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns195(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame(195, $task->getOrder());
    }

    #[Test]
    public function runUnsuspendsExpiredSuspensionsAndLogsToDebug(): void
    {
        $nick1 = $this->createNickWithId('Nick1', 1);
        $nick1->suspend('Expired ban', new DateTimeImmutable('-1 hour'));

        $nick2 = $this->createNickWithId('Nick2', 2);
        $nick2->suspend('Expired ban', new DateTimeImmutable('-1 day'));

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findExpiredSuspensions')->willReturn([$nick1, $nick2]);
        $nickRepo->expects(self::exactly(2))->method('save');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::exactly(2))->method('log')
            ->willReturnCallback(static function (string $operator, string $command, string $target): void {
                self::assertSame(self::SERVER_NAME, $operator);
                self::assertSame('UNSUSPEND', $command);
            });

        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();

        self::assertFalse($nick1->isSuspended());
        self::assertFalse($nick2->isSuspended());
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredSuspensions(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findExpiredSuspensions')->willReturn([]);
        $nickRepo->expects(self::never())->method('save');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('log');

        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function runUnsuspendsCorrectNickById(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->suspend('Expired suspension', new DateTimeImmutable('-1 hour'));

        self::assertTrue($nick->isSuspended());

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')->willReturn([$nick]);

        $unsuspended = [];
        $nickRepo->method('save')
            ->willReturnCallback(static function (RegisteredNick $n) use (&$unsuspended): void {
                $unsuspended[] = $n;
            });

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            self::SERVER_NAME,
            'UNSUSPEND',
            'TestNick',
        );

        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();

        self::assertCount(1, $unsuspended);
        self::assertFalse($nick->isSuspended());
        self::assertNull($nick->getReason());
        self::assertNull($nick->getSuspendedUntil());
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', $nickname . '@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }
}
