<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\ForbidVhost;

final readonly class ManageForbiddenVhost
{
    public function __construct(
        public ForbiddenVhostAction $action,
        public ?string $pattern,
        public ?int $creatorNickId,
    ) {}
}
