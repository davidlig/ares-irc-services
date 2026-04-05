<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

use function fnmatch;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

use const FNM_CASEFOLD;

class ForbiddenVhost
{
    public const int MAX_PATTERN_LENGTH = 255;

    private int $id;

    private string $pattern;

    private ?int $createdByNickId = null;

    private DateTimeImmutable $createdAt;

    private function __construct(
        string $pattern,
        ?int $createdByNickId,
    ) {
        $this->setPattern($pattern);
        $this->createdByNickId = $createdByNickId;
        $this->createdAt = new DateTimeImmutable();
    }

    public static function create(string $pattern, ?int $createdByNickId = null): self
    {
        return new self($pattern, $createdByNickId);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getCreatedByNickId(): ?int
    {
        return $this->createdByNickId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function matches(string $vhost): bool
    {
        $patternLower = strtolower($this->pattern);
        $vhostLower = strtolower($vhost);

        return fnmatch($patternLower, $vhostLower, FNM_CASEFOLD);
    }

    private function setPattern(string $pattern): void
    {
        $normalized = trim($pattern);

        if ('' === $normalized) {
            throw new InvalidArgumentException('Pattern cannot be empty.');
        }

        if (strlen($normalized) > self::MAX_PATTERN_LENGTH) {
            throw new InvalidArgumentException(sprintf('Pattern cannot exceed %d characters.', self::MAX_PATTERN_LENGTH));
        }

        $this->pattern = $normalized;
    }
}
