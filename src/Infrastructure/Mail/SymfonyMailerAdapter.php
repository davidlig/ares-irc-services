<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Application\Mail\MailerInterface;
use Symfony\Component\Mailer\MailerInterface as SymfonyMailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends emails via Symfony Mailer.
 */
final class SymfonyMailerAdapter implements MailerInterface
{
    public function __construct(
        private readonly SymfonyMailerInterface $mailer,
        private readonly string $from,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }
}
