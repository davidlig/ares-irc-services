<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\IRC\ValueObject\UserMask;

/**
 * DTO for "user who sent a command" or "user on network" as seen by Services.
 *
 * Services MUST NOT depend on Domain\IRC\Network\NetworkUser.
 * Core implements NetworkUserLookupPort and returns this DTO.
 */
final readonly class SenderView
{
    public function __construct(
        public string $uid,
        public string $nick,
        public string $ident,
        public string $hostname,
        public string $cloakedHost,
        public string $ipBase64,
        public bool $isIdentified = false,
        public bool $isOper = false,
        public string $serverSid = '',
        /** Host currently displayed by the IRCd (vhost if set, else cloakedHost). */
        public string $displayHost = '',
    ) {
    }

    public function toUserMask(): UserMask
    {
        return UserMask::fromParts($this->nick, $this->ident, $this->hostname);
    }
}
