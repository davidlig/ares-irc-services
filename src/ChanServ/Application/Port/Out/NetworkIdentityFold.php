<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

/** Folds nicknames, channel names and nick!ident@host masks with the casemapping the IRCd negotiated. */
interface NetworkIdentityFold
{
    public function fold(string $value): string;
}
