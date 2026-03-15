<?php

declare(strict_types=1);

namespace App\Application\Port;

interface UserMessageTypeResolverInterface
{
    public function resolve(SenderView $sender): string;

    public function resolveByNick(string $nick): string;
}
