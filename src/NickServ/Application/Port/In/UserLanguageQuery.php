<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

interface UserLanguageQuery
{
    public function resolve(string $uid, string $nickname): string;

    public function resolveFromAccount(string $uid, ?string $accountLanguage): string;

    public function resolveByNick(string $nickname): string;

    public function getDefault(): string;
}
