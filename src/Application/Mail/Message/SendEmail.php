<?php

declare(strict_types=1);

namespace App\Application\Mail\Message;

/**
 * Command to send a plain-text email (dispatched to async transport).
 */
final readonly class SendEmail
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
    ) {
    }
}
