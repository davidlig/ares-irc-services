<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

final readonly class AssociatedChannel
{
    public function __construct(
        public string $name,
        /** @var 'founder'|'successor'|'access' */
        public string $type,
        public ?int $level = null,
    ) {}
}
