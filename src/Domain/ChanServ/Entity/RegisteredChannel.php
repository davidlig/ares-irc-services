<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

use function sprintf;
use function strlen;

/**
 * A registered IRC channel. Founder and successor are stored as nick IDs (FK to NickServ).
 *
 * Persistence mapping is defined in config/doctrine (XML); Domain has no Doctrine dependency.
 */
class RegisteredChannel
{
    public const int ENTRYMSG_MAX_LENGTH = 255;

    private int $id;

    private string $name;

    private string $nameLower;

    private int $founderNickId;

    private ?int $successorNickId = null;

    private string $description = '';

    private ?string $url = null;

    private ?string $email = null;

    private string $entrymsg = '';

    private bool $topicLock = false;

    /** Whether MLOCK is on (true) or off (false). Two explicit states. */
    private bool $mlockActive = false;

    /** Channel modes to lock when MLOCK is on (e.g. +nt or +ntl). Empty when active but no modes to lock. */
    private string $mlock = '';

    /** Params for MLOCK modes that take one (e.g. l => 100, k => key). Key = mode letter. */
    private array $mlockParams = [];

    private bool $secure = false;

    private ?string $topic = null;

    private ?DateTimeImmutable $lastTopicSetAt = null;

    /** Nickname of who last set the topic (null if set by services or unknown). */
    private ?string $lastTopicSetByNick = null;

    private ?DateTimeImmutable $lastUsedAt = null;

    private DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public static function register(
        string $channelName,
        int $founderNickId,
        string $description,
    ): self {
        $channel = new self();
        $channel->name = $channelName;
        $channel->nameLower = strtolower($channelName);
        $channel->founderNickId = $founderNickId;
        $channel->description = $description;
        $channel->lastUsedAt = new DateTimeImmutable();

        return $channel;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNameLower(): string
    {
        return $this->nameLower;
    }

    public function getFounderNickId(): int
    {
        return $this->founderNickId;
    }

    public function getSuccessorNickId(): ?int
    {
        return $this->successorNickId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getEntrymsg(): string
    {
        return $this->entrymsg;
    }

    public function isTopicLock(): bool
    {
        return $this->topicLock;
    }

    public function getMlock(): string
    {
        return $this->mlock;
    }

    /**
     * @return array<string, string> Mode letter => param value (e.g. ['l' => '100', 'k' => 'key'])
     */
    public function getMlockParams(): array
    {
        return $this->mlockParams;
    }

    public function getMlockParam(string $letter): ?string
    {
        return $this->mlockParams[$letter] ?? null;
    }

    public function isMlockActive(): bool
    {
        return $this->mlockActive;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function getTopic(): ?string
    {
        return $this->topic;
    }

    public function getLastTopicSetAt(): ?DateTimeImmutable
    {
        return $this->lastTopicSetAt;
    }

    public function getLastTopicSetByNick(): ?string
    {
        return $this->lastTopicSetByNick;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function changeFounder(int $newFounderNickId): void
    {
        $this->founderNickId = $newFounderNickId;
        $this->successorNickId = null;
    }

    public function assignSuccessor(?int $nickId): void
    {
        $this->successorNickId = $nickId;
    }

    public function updateDescription(string $description): void
    {
        $this->description = $description;
    }

    public function updateUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function updateEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function updateEntrymsg(string $entrymsg): void
    {
        if (strlen($entrymsg) > self::ENTRYMSG_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Entry message cannot exceed %d characters.', self::ENTRYMSG_MAX_LENGTH));
        }
        $this->entrymsg = $entrymsg;
    }

    public function configureTopicLock(bool $on): void
    {
        $this->topicLock = $on;
    }

    public function configureMlock(bool $active, string $modeString = '', array $params = []): void
    {
        $this->mlockActive = $active;
        $this->mlock = $modeString;
        $this->mlockParams = $params;
    }

    public function configureSecure(bool $on): void
    {
        $this->secure = $on;
    }

    public function updateTopic(?string $topic, ?string $setByNick = null): void
    {
        $this->topic = $topic;
        $this->lastTopicSetAt = null !== $topic ? new DateTimeImmutable() : null;
        $this->lastTopicSetByNick = null !== $topic ? $setByNick : null;
    }

    public function touchLastUsed(): void
    {
        $this->lastUsedAt = new DateTimeImmutable();
    }

    public function isFounder(int $nickId): bool
    {
        return $this->founderNickId === $nickId;
    }
}
