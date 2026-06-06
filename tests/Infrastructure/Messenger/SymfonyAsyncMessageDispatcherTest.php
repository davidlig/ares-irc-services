<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messenger;

use App\Infrastructure\Messenger\SymfonyAsyncMessageDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(SymfonyAsyncMessageDispatcher::class)]
final class SymfonyAsyncMessageDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchDelegatesToSymfonyMessageBusAndReturnsEnvelope(): void
    {
        $message = new stdClass();
        $envelope = new Envelope($message);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($message)
            ->willReturn($envelope);

        $dispatcher = new SymfonyAsyncMessageDispatcher($messageBus);

        self::assertSame($envelope, $dispatcher->dispatch($message));
    }
}
