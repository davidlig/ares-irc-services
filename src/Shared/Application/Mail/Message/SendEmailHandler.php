<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail\Message;

use App\Shared\Application\Mail\MailerInterface;

final readonly class SendEmailHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {}

    public function __invoke(SendEmail $message): void
    {
        $this->mailer->send($message->to, $message->subject, $message->body);
    }
}
