<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messenger\Middleware;

use App\Application\Mail\Message\SendEmail;
use App\Infrastructure\Messenger\Middleware\EmailDelayMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(EmailDelayMiddleware::class)]
final class EmailDelayMiddlewareTest extends TestCase
{
    #[Test]
    public function handlePassesThroughNonSendEmailMessages(): void
    {
        $middleware = new EmailDelayMiddleware(5);

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
        $middleware = new EmailDelayMiddleware(5);

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
        $middleware = new EmailDelayMiddleware(0);

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
        $middleware = new EmailDelayMiddleware(-1);

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
        $middleware = new EmailDelayMiddleware(1);

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

        $start = hrtime(true);
        $result = $middleware->handle($envelope, $stack);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertSame($envelope, $result);
        self::assertGreaterThanOrEqual(900, $elapsedMs, 'EmailDelayMiddleware should sleep at least ~1 second');
    }
}
