<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail\Message;

/**
 * Command to send a plain-text email (dispatched to async transport).
 */
final readonly class SendEmail
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {}
}
