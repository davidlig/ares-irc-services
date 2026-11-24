<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Connection;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Amp\TimeoutCancellation;
use App\Irc\Adapter\Out\Connection\RetryingSocketConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryingSocketConnector::class)]
final class RetryingSocketConnectorTest extends TestCase
{
    #[Test]
    public function retriesAFailedAttemptAndPassesTheContextAndCancellation(): void
    {
        $socket = $this->createStub(Socket::class);
        $context = new ConnectContext()->withTcpNoDelay()->withBindTo('127.0.0.1:0');
        // DeferredCancellation cancels when destroyed, so the source must stay referenced.
        $source = new DeferredCancellation();
        $cancellation = $source->getCancellation();
        $delegate = $this->createMock(SocketConnector::class);
        $delegate->expects(self::exactly(2))->method('connect')
            ->with('uplink.test:7000', self::callback(static function (ConnectContext $passed): bool {
                // Each attempt keeps the caller's options with only the time left as its timeout.
                self::assertTrue($passed->hasTcpNoDelay());
                self::assertSame('127.0.0.1:0', $passed->getBindTo());

                return true;
            }), $cancellation)
            ->willReturnOnConsecutiveCalls(self::throwException(new ConnectException('refused')), $socket);

        self::assertSame($socket, new RetryingSocketConnector($delegate, backoffBaseSeconds: 0.001, minAttemptSeconds: 0.001)->connect('uplink.test:7000', $context, $cancellation));
    }

    #[Test]
    public function reportsEveryAttemptAfterTheLastOneFails(): void
    {
        $delegate = $this->createStub(SocketConnector::class);
        $delegate->method('connect')->willReturnOnConsecutiveCalls(
            self::throwException(new ConnectException('first')),
            self::throwException(new ConnectException('second')),
            self::throwException(new ConnectException('third')),
        );

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to uplink.test:7000 failed after 3 attempts: attempt 1: first; attempt 2: second; attempt 3: third');

        new RetryingSocketConnector($delegate, backoffBaseSeconds: 0.001, minAttemptSeconds: 0.001)->connect('uplink.test:7000');
    }

    #[Test]
    public function keepsTheFailureReasonWhenTheCallerCancelsDuringTheBackoff(): void
    {
        $refused = new ConnectException('refused');
        $delegate = $this->createMock(SocketConnector::class);
        $delegate->expects(self::once())->method('connect')->willThrowException($refused);

        try {
            new RetryingSocketConnector($delegate)->connect('uplink.test:7000', cancellation: new TimeoutCancellation(0.01));
            self::fail('Expected the cancellation to stop the retry.');
        } catch (CancelledException $error) {
            $reason = $error->getPrevious();
            self::assertInstanceOf(ConnectException::class, $reason);
            self::assertSame('Connection to uplink.test:7000 was cancelled after 1 failed attempt: attempt 1: refused', $reason->getMessage());
        }
    }

    #[Test]
    public function addsTheAddressFailuresOfACancelledAttemptToEarlierFailures(): void
    {
        $delegate = $this->createStub(SocketConnector::class);
        $delegate->method('connect')->willReturnOnConsecutiveCalls(
            self::throwException(new ConnectException('refused')),
            self::throwException(new CancelledException(new ConnectException('a (connection timeout)'))),
        );

        try {
            new RetryingSocketConnector($delegate, backoffBaseSeconds: 0.001, minAttemptSeconds: 0.001)->connect('uplink.test:7000');
            self::fail('Expected the cancellation to stop the retry.');
        } catch (CancelledException $error) {
            $reason = $error->getPrevious();
            self::assertInstanceOf(ConnectException::class, $reason);
            self::assertSame('Connection to uplink.test:7000 was cancelled after 2 failed attempts: attempt 1: refused; attempt 2: a (connection timeout)', $reason->getMessage());
        }
    }

    #[Test]
    public function keepsAPlainCancellationOfALaterAttemptWithEarlierFailures(): void
    {
        $cancelled = new CancelledException();
        $delegate = $this->createStub(SocketConnector::class);
        $delegate->method('connect')->willReturnOnConsecutiveCalls(
            self::throwException(new ConnectException('refused')),
            self::throwException($cancelled),
        );

        try {
            new RetryingSocketConnector($delegate, backoffBaseSeconds: 0.001, minAttemptSeconds: 0.001)->connect('uplink.test:7000');
            self::fail('Expected the cancellation to stop the retry.');
        } catch (CancelledException $error) {
            $reason = $error->getPrevious();
            self::assertInstanceOf(ConnectException::class, $reason);
            self::assertSame('Connection to uplink.test:7000 was cancelled after 1 failed attempt: attempt 1: refused', $reason->getMessage());
            self::assertSame($cancelled, $reason->getPrevious());
        }
    }

    #[Test]
    public function givesEachAttemptOnlyTheConnectTimeoutThatIsLeft(): void
    {
        $timeouts = [];
        $delegate = $this->createStub(SocketConnector::class);
        $delegate->method('connect')->willReturnCallback(static function (string $uri, ConnectContext $context) use (&$timeouts): never {
            $timeouts[] = $context->getConnectTimeout();

            throw new ConnectException('refused');
        });

        try {
            new RetryingSocketConnector($delegate, backoffBaseSeconds: 0.1, minAttemptSeconds: 0.1)->connect('uplink.test:7000', new ConnectContext()->withConnectTimeout(1.0));
            self::fail('Expected every attempt to fail.');
        } catch (ConnectException) {
            self::assertCount(3, $timeouts);
            self::assertEqualsWithDelta(1.0, $timeouts[0], 0.01);
            self::assertLessThanOrEqual(0.9, $timeouts[1]);
            self::assertLessThanOrEqual(0.89, $timeouts[2]);
        }
    }

    #[Test]
    public function givesUpWhenTheNextRetryWouldNotLeaveTheMinimumAttemptTime(): void
    {
        $refused = new ConnectException('refused');
        $delegate = $this->createMock(SocketConnector::class);
        $delegate->expects(self::once())->method('connect')->willThrowException($refused);

        try {
            // A 2 s backoff fits in 2.5 s, but would leave less than the 1 s minimum for the retry.
            new RetryingSocketConnector($delegate)->connect('uplink.test:7000', new ConnectContext()->withConnectTimeout(2.5));
            self::fail('Expected the connector to give up.');
        } catch (ConnectException $error) {
            self::assertSame('Connection to uplink.test:7000 gave up after 1 failed attempt; the next retry would not fit in the time left: attempt 1: refused', $error->getMessage());
            self::assertSame($refused, $error->getPrevious());
        }
    }

    #[Test]
    public function rejectsAMinimumAttemptTimeThatIsNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The minimum attempt time must be greater than 0 seconds.');

        new RetryingSocketConnector($this->createStub(SocketConnector::class), minAttemptSeconds: 0.0);
    }

    #[Test]
    public function rethrowsACancellationBeforeAnyFailure(): void
    {
        $cancelled = new CancelledException();
        $delegate = $this->createStub(SocketConnector::class);
        $delegate->method('connect')->willThrowException($cancelled);

        try {
            new RetryingSocketConnector($delegate)->connect('uplink.test:7000');
            self::fail('Expected the cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancelled, $error);
        }
    }

    #[Test]
    public function rejectsABackoffBaseThatIsNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The backoff base must be greater than 0 seconds.');

        new RetryingSocketConnector($this->createStub(SocketConnector::class), backoffBaseSeconds: 0.0);
    }

    #[Test]
    public function rejectsFewerThanOneAttempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The maximum attempts must be at least 1.');

        new RetryingSocketConnector($this->createStub(SocketConnector::class), 0);
    }
}
