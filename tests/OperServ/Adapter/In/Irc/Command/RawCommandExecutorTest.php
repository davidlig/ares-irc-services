<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\ActiveConnectionHolderInterface;
use App\OperServ\Adapter\In\Irc\Command\ProtocolRawCommandInterceptorInterface;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutionOutcome;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutionResult;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RawCommandExecutor::class)]
#[CoversClass(RawCommandExecutionResult::class)]
final class RawCommandExecutorTest extends TestCase
{
    #[Test]
    public function validatesBeforeCallingProtocolBoundary(): void
    {
        $interceptor = $this->createMock(ProtocolRawCommandInterceptorInterface::class);
        $interceptor->expects(self::never())->method('intercept');
        $connection = $this->connection();
        $executor = new RawCommandExecutor($connection, $interceptor);

        self::assertSame(RawCommandExecutionOutcome::Empty, $executor->execute([])->outcome);
        self::assertSame(RawCommandExecutionOutcome::TooLong, $executor->execute([str_repeat('x', 511)])->outcome);
        $disconnected = new RawCommandExecutor($this->connection(false), $interceptor);
        self::assertSame(RawCommandExecutionOutcome::Disconnected, $disconnected->execute(['PING'])->outcome);
    }

    #[Test]
    public function writesUnhandledLineAndKeepsOnlyItsCommandName(): void
    {
        $connection = $this->createMock(ActiveConnectionHolderInterface::class);
        $connection->expects(self::exactly(3))->method('isConnected')->willReturn(true);
        $connection->expects(self::exactly(3))->method('writeLine')->withAnyParameters();
        $interceptor = $this->createStub(ProtocolRawCommandInterceptorInterface::class);
        $interceptor->method('intercept')->willReturn(null);
        $executor = new RawCommandExecutor($connection, $interceptor);

        self::assertSame('KILL', $executor->execute([':001', 'KILL', 'secret'])->operation);
        self::assertSame('PING', $executor->execute(['PING'])->operation);
        self::assertSame('UNKNOWN', $executor->execute(['', 'payload'])->operation);
    }

    #[Test]
    public function returnsInterceptedExecutionWithoutWritingRawLine(): void
    {
        $connection = $this->connection();
        $interceptor = $this->createStub(ProtocolRawCommandInterceptorInterface::class);
        $interceptor->method('intercept')->willReturn(RawCommandExecutionResult::executed('PROTOCOL ACTION', true));

        $result = new RawCommandExecutor($connection, $interceptor)->execute(['opaque']);

        self::assertSame(RawCommandExecutionOutcome::Intercepted, $result->outcome);
        self::assertSame('PROTOCOL ACTION', $result->operation);
    }

    #[Test]
    public function returnsProtocolOwnedRejectionsUnchanged(): void
    {
        $interceptor = $this->createStub(ProtocolRawCommandInterceptorInterface::class);
        $expected = RawCommandExecutionResult::rejected(
            RawCommandExecutionOutcome::ValueInvalid,
            resourceType: 'type',
            resourceIdentifier: 'resource',
        );
        $interceptor->method('intercept')->willReturn($expected);

        $result = new RawCommandExecutor($this->connection(), $interceptor)->execute(['opaque']);

        self::assertSame($expected, $result);
        self::assertSame('type', $result->resourceType);
        self::assertSame('resource', $result->resourceIdentifier);
    }

    private function connection(bool $connected = true): ActiveConnectionHolderInterface
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $connection->method('isConnected')->willReturn($connected);

        return $connection;
    }
}
