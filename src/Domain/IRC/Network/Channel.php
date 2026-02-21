<?php

declare(strict_types=1);

namespace App\Domain\IRC\Network;

use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;

/**
 * IRC channel aggregate root.
 *
 * Tracks members (with their privilege roles), mode string, ban/exempt/invex
 * lists, and topic. Updated from SJOIN, MODE, TOPIC, PART, KICK, QUIT messages.
 */
class Channel
{
    /** @var array<string, ChannelMember> keyed by UID string */
    private array $members = [];

    /** @var string[] ban masks */
    private array $bans = [];

    /** @var string[] ban exemption masks */
    private array $exempts = [];

    /** @var string[] invite exception masks */
    private array $inviteExceptions = [];

    private ?string $topic = null;
    private string $modes;

    public function __construct(
        public readonly ChannelName $name,
        string $modes = '',
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->modes = $modes;
    }

    // --- Members ---

    public function syncMember(Uid $uid, ChannelMemberRole $role): void
    {
        $this->members[$uid->value] = new ChannelMember($uid, $role);
    }

    public function removeMember(Uid $uid): void
    {
        unset($this->members[$uid->value]);
    }

    public function getMember(Uid $uid): ?ChannelMember
    {
        return $this->members[$uid->value] ?? null;
    }

    public function isMember(Uid $uid): bool
    {
        return isset($this->members[$uid->value]);
    }

    /**
     * @return ChannelMember[]
     */
    public function getMembers(): array
    {
        return array_values($this->members);
    }

    public function getMemberCount(): int
    {
        return count($this->members);
    }

    // --- Modes ---

    public function getModes(): string
    {
        return $this->modes;
    }

    public function setModes(string $modes): void
    {
        $this->modes = $modes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updateCreatedAt(\DateTimeImmutable $ts): void
    {
        $this->createdAt = $ts;
    }

    // --- Topic ---

    public function getTopic(): ?string
    {
        return $this->topic;
    }

    public function setTopic(?string $topic): void
    {
        $this->topic = $topic;
    }

    // --- Ban list (+b) ---

    public function addBan(string $mask): void
    {
        if (!in_array($mask, $this->bans, true)) {
            $this->bans[] = $mask;
        }
    }

    public function removeBan(string $mask): void
    {
        $this->bans = array_filter($this->bans, static fn (string $m): bool => $m !== $mask);
    }

    /** @return string[] */
    public function getBans(): array
    {
        return array_values($this->bans);
    }

    // --- Exempt list (+e) ---

    public function addExempt(string $mask): void
    {
        if (!in_array($mask, $this->exempts, true)) {
            $this->exempts[] = $mask;
        }
    }

    public function removeExempt(string $mask): void
    {
        $this->exempts = array_filter($this->exempts, static fn (string $m): bool => $m !== $mask);
    }

    /** @return string[] */
    public function getExempts(): array
    {
        return array_values($this->exempts);
    }

    // --- Invite exception list (+I) ---

    public function addInviteException(string $mask): void
    {
        if (!in_array($mask, $this->inviteExceptions, true)) {
            $this->inviteExceptions[] = $mask;
        }
    }

    public function removeInviteException(string $mask): void
    {
        $this->inviteExceptions = array_filter(
            $this->inviteExceptions,
            static fn (string $m): bool => $m !== $mask
        );
    }

    /** @return string[] */
    public function getInviteExceptions(): array
    {
        return array_values($this->inviteExceptions);
    }

    public function toArray(): array
    {
        return [
            'name'      => $this->name->value,
            'modes'     => $this->modes,
            'topic'     => $this->topic,
            'members'   => $this->getMemberCount(),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
