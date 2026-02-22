<?php

declare(strict_types=1);

namespace App\Application\Mail;

/**
 * Port for sending emails. Implementations live in Infrastructure (e.g. Symfony Mailer).
 */
interface MailerInterface
{
    /**
     * Sends a plain-text email.
     */
    public function send(string $to, string $subject, string $body): void;
}
