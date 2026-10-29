<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

/** Complete current identity used only inside IRC protocol adapters. */
final readonly class ChannelBanIdentity
{
    public function __construct(
        public string $nick,
        public string $ident,
        public string $realIdent,
        public string $realHost,
        public string $displayHost,
        public string $cloakedHost,
        public ?string $ip,
    ) {}
}
