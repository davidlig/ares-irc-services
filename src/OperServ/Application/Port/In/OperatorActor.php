<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

final readonly class OperatorActor
{
    public function __construct(
        public string $nickname,
        public ?int $identifiedAccountId,
        public bool $identified,
        public bool $ircOperator,
    ) {}
}
