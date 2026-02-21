<?php

declare(strict_types=1);

namespace App\Domain\IRC\Network;

use App\Domain\IRC\ValueObject\Uid;

/**
 * Represents a user's membership and privilege level in a channel.
 * Immutable — the Channel aggregate creates a new instance when the role changes.
 */
readonly class ChannelMember
{
    public function __construct(
        public readonly Uid $uid,
        public readonly ChannelMemberRole $role,
    ) {
    }

    public function withRole(ChannelMemberRole $role): self
    {
        return new self($this->uid, $role);
    }
}
