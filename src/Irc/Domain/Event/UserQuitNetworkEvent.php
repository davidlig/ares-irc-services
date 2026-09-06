<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;

/**
 * Dispatched when a QUIT command is received: a user has disconnected from the network.
 *
 * NOTE: this event is dispatched BEFORE the user is removed from the
 * NetworkUserRepository so subscribers can still access the full user object
 * if needed. ident and displayHost are provided directly to avoid re-lookups.
 */
final readonly class UserQuitNetworkEvent
{
    public function __construct(
        public Uid $uid,
        public Nick $nick,
        public string $reason,
        /** IRC ident (username) of the user, e.g. "david" */
        public string $ident = '',
        /** Best available hostname (vhost > cloaked host), e.g. "Clk-1C178BB8" */
        public string $displayHost = '',
        /** Real hostname from IRCd, e.g. "user.isp.com" */
        public string $hostname = '',
        /** IP address in base64 format from IRCd */
        public string $ipBase64 = '',
    ) {}
}
