<?php

declare(strict_types=1);

namespace App\Domain\IRC\Network;

use App\Domain\IRC\ValueObject\Uid;

/**
 * Represents a user's membership and privilege level in a channel.
 * Immutable — the Channel aggregate creates a new instance when the role changes.
 * prefixLetters is the actual set of prefix modes (q,a,o,h,v) the user has; when empty we derive from role.
 */
readonly class ChannelMember
{
    /** @var list<string> */
    public readonly array $prefixLetters;

    public function __construct(
        public readonly Uid $uid,
        public readonly ChannelMemberRole $role,
        ?array $prefixLetters = null,
    ) {
        if (null !== $prefixLetters) {
            $this->prefixLetters = $prefixLetters;
        } else {
            $this->prefixLetters = ChannelMemberRole::None !== $this->role
                ? [$this->role->toModeLetter()]
                : [];
        }
    }

    public function withRole(ChannelMemberRole $role): self
    {
        return new self($this->uid, $role);
    }

    public function withPrefixLetters(array $prefixLetters): self
    {
        $role = ChannelMemberRole::highestRoleFromLetters($prefixLetters);

        return new self($this->uid, $role, $prefixLetters);
    }
}
