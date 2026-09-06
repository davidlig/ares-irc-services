<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Mail;

final readonly class RegistrationVerificationEmail
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {}
}
