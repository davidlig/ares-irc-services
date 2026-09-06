<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Irc\Application\Port\In\SenderView;

interface UserMessageTypeResolverInterface
{
    /**
     * @return 'NOTICE'|'PRIVMSG'
     */
    public function resolve(SenderView $sender): string;

    /**
     * @return 'NOTICE'|'PRIVMSG'
     */
    public function resolveByNick(string $nick): string;
}
