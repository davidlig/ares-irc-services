<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Register;

final readonly class RegisterNick
{
    public function __construct(
        public string $nickname,
        public string $password,
        public string $email,
        public string $language,
        public string $clientKey,
    ) {}
}
