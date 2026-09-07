<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Mail;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class FounderChangeEmailHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private string $from,
        private string $senderName,
        private int $sendDelaySeconds,
    ) {}

    public function __invoke(FounderChangeEmail $message): void
    {
        $parsed = Address::create($this->from);
        $from = new Address($parsed->getAddress(), $this->senderName);
        $email = new Email()
            ->from($from)
            ->to($message->to)
            ->subject($message->subject)
            ->text($message->body);

        $this->mailer->send($email);

        if (0 < $this->sendDelaySeconds) {
            $this->clock->sleep($this->sendDelaySeconds);
        }
    }
}
