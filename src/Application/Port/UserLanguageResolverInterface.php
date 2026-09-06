<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Domain\Entity\RegisteredNick;

interface UserLanguageResolverInterface
{
    public function resolve(SenderView $user): string;

    public function resolveFromAccount(SenderView $user, ?RegisteredNick $account): string;

    public function resolveByNick(string $nick): string;

    public function getDefault(): string;
}
