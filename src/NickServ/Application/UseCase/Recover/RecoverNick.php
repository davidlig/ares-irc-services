<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

use SensitiveParameter;

final readonly class RecoverNick
{
    public function __construct(
        public string $nickname,
        #[SensitiveParameter]
        public ?string $token = null,
        public ?string $senderNick = null,
        public ?int $senderAccountId = null,
        public ?string $senderIp = null,
        public ?string $senderHost = null,
        public string $clientKey = '',
    ) {}
}
