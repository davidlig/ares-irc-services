<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Mail;

final readonly class FounderChangeEmail
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {}
}
