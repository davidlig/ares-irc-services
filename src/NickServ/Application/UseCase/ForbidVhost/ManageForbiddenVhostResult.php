<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\ForbidVhost;

final readonly class ManageForbiddenVhostResult
{
    /** @param list<ForbiddenVhostView> $entries */
    public function __construct(
        public ManageForbiddenVhostOutcome $outcome,
        public ?string $pattern = null,
        public array $entries = [],
    ) {}
}
