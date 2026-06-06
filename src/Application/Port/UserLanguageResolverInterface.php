<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\NickServ\Entity\RegisteredNick;

interface UserLanguageResolverInterface
{
    public function resolve(SenderView $user): string;

    public function resolveFromAccount(SenderView $user, ?RegisteredNick $account): string;

    public function resolveByNick(string $nick): string;

    public function getDefault(): string;
}
