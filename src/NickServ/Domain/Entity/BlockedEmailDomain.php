<?php

declare(strict_types=1);

namespace App\NickServ\Domain\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;

/**
 * An e-mail domain on which no user nickname may be registered.
 *
 * The stored value is always normalised (trimmed and lowercased) so the
 * uniqueness constraint and lookups do not depend on how an operator typed it.
 */
class BlockedEmailDomain
{
    public const int MAX_DOMAIN_LENGTH = 253;

    private int $id;

    private string $domain;

    private DateTimeImmutable $createdAt;

    public function __construct(string $domain, DateTimeImmutable $createdAt)
    {
        $normalized = self::normalize($domain);

        if (!self::isValid($normalized)) {
            throw new InvalidArgumentException(sprintf('Invalid e-mail domain "%s".', $domain));
        }

        $this->domain = $normalized;
        $this->createdAt = $createdAt;
    }

    public static function normalize(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public static function isValid(string $domain): bool
    {
        return '' !== $domain
            && strlen($domain) <= self::MAX_DOMAIN_LENGTH
            && str_contains($domain, '.')
            && !str_contains($domain, '@')
            && 0 === preg_match('/\s/u', $domain)
            && !str_starts_with($domain, '.')
            && !str_ends_with($domain, '.');
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
