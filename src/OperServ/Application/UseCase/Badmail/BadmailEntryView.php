<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Badmail;

final readonly class BadmailEntryView
{
    public function __construct(public int $id, public string $domain, public string $reason) {}
}
