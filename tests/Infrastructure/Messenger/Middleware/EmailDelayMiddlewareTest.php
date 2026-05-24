<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messenger\Middleware;

use App\Application\Mail\Message\SendEmail;
use App\Infrastructure\Messenger\Middleware\EmailDelayMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(EmailDelayMiddleware::class)]
final class EmailDelayMiddlewareTest extends TestCase
{
    private function createMiddleware(int $delaySeconds, ?ClockInterface $clock = null): EmailDelayMiddleware
    {
        return new EmailDelayMiddleware($delaySeconds, $clock ?? $this->createStub(ClockInterface::class));
    }

    #[Test]
    public function handlePassesThroughNonSendEmailMessages(): void
    {
        $middleware = $this->createMiddleware(5);

        $message = new stdClass();
        $envelope = new Envelope($message);

        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())
            ->method('next')
            ->willReturn($next);

        $result = $middleware->handle($envelope, $stack);

        self::assertSame($envelope, $result);
    }

    #[Test]
    public function handlePassesThroughSendEmailWithoutReceivedStamp(): void
    {
        $middleware = $this->createMiddleware(5);

        $message = new SendEmail('test@example.com', 'Subject', 'Body');
        $envelope = new Envelope($message);

        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())
            ->method('next')
            ->willReturn($next);

        $result = $middleware->handle($envelope, $stack);

        self::assertSame($envelope, $result);
    }

    #[Test]
    public function handlePassesThroughSendEmailWithZeroDelay(): void
    {
        $middleware = $this->createMiddleware(0);

        $message = new SendEmail('test@example.com', 'Subject', 'Body');
        $envelope = new Envelope($message, [new ReceivedStamp('async_emails')]);

        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())
            ->method('next')
            ->willReturn($next);

        $result = $middleware->handle($envelope, $stack);

        self::assertSame($envelope, $result);
    }

    #[Test]
    public function handlePassesThroughSendEmailWithNegativeDelay(): void
    {
        $middleware = $this->createMiddleware(-1);

        $message = new SendEmail('test@example.com', 'Subject', 'Body');
        $envelope = new Envelope($message, [new ReceivedStamp('async_emails')]);

        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())
            ->method('next')
            ->willReturn($next);

        $result = $middleware->handle($envelope, $stack);

        self::assertSame($envelope, $result);
    }

    #[Test]
    public function handleDelaysWhenSendEmailWithReceivedStampAndPositiveDelay(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('sleep')
            ->with(1);

        $middleware = $this->createMiddleware(1, $clock);

        $message = new SendEmail('test@example.com', 'Subject', 'Body');
        $envelope = new Envelope($message, [new ReceivedStamp('async_emails')]);

        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())
            ->method('next')
            ->willReturn($next);

        $result = $middleware->handle($envelope, $stack);

        self::assertSame($envelope, $result);
    }
}
