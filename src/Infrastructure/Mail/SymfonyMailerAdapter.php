<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Application\Mail\MailerInterface;
use Symfony\Component\Mailer\MailerInterface as SymfonyMailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends emails via Symfony Mailer.
 * The visible sender name is the IRC network name (irc.server_name), not the bot name.
 */
final readonly class SymfonyMailerAdapter implements MailerInterface
{
    public function __construct(
        private readonly SymfonyMailerInterface $mailer,
        private readonly string $from,
        private readonly string $senderName,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $parsed = Address::create($this->from);
        $fromAddress = new Address($parsed->getAddress(), $this->senderName);

        $email = (new Email())
            ->from($fromAddress)
            ->to($to)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }
}
