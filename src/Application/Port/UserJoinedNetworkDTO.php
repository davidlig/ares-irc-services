<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * DTO for "user joined network" event as seen by Services.
 *
 * Services MUST NOT depend on Domain\IRC\Network\NetworkUser.
 * This DTO carries the minimum data Services need when a user joins the network.
 */
final readonly class UserJoinedNetworkDTO
{
    public function __construct(
        public string $uid,
        public string $nick,
        public string $ident,
        public string $hostname,
        public string $cloakedHost,
        public string $ipBase64,
        public string $displayHost,
        public bool $isIdentified = false,
        public bool $isOper = false,
        public string $serverSid = '',
    ) {
    }
}
