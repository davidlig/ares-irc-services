<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger\Middleware;

use App\Application\Mail\Message\SendEmail;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Applies a configurable delay after handling SendEmail messages in the consumer.
 * Only runs when the message was received from a transport (worker), not when dispatching.
 * Used to avoid provider rate limits when processing the async_emails queue.
 */
final readonly class EmailDelayMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $emailDelaySeconds,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $result = $stack->next()->handle($envelope, $stack);

        // Only delay in the consumer (message has ReceivedStamp), not when dispatching from CLI/IRC
        if (
            $envelope->getMessage() instanceof SendEmail
            && $this->emailDelaySeconds > 0
            && [] !== $envelope->all(ReceivedStamp::class)
        ) {
            sleep($this->emailDelaySeconds);
        }

        return $result;
    }
}
