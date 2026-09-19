<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Rename;

final readonly class RenameNick
{
    public function __construct(
        public string $targetNick,
        public ?string $targetUid = null,
        public ?string $targetIdent = null,
        public ?string $targetHostname = null,
        public ?string $targetIp = null,
        public string $operatorNick = '',
    ) {}
}
