<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger\Middleware;

use App\Application\Mail\Message\SendEmail;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Applies a configurable delay after handling SendEmail messages.
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

        if ($envelope->getMessage() instanceof SendEmail && $this->emailDelaySeconds > 0) {
            sleep($this->emailDelaySeconds);
        }

        return $result;
    }
}
