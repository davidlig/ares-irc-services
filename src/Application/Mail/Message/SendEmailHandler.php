<?php

declare(strict_types=1);

namespace App\Application\Mail\Message;

use App\Application\Mail\MailerInterface;

final readonly class SendEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendEmail $message): void
    {
        $this->mailer->send($message->to, $message->subject, $message->body);
    }
}
