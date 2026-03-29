<?php

declare(strict_types=1);

namespace App\Domain\OperServ\ValueObject;

use DomainException;

use function preg_match;
use function preg_replace;
use function strlen;
use function trim;

final readonly class ForcedVhost
{
    private const string LABEL_PATTERN = '[a-zA-Z0-9]+(-[a-zA-Z0-9]+)*';

    private const string VHOST_PATTERN = '/^' . self::LABEL_PATTERN . '(\\.' . self::LABEL_PATTERN . ')+$/';

    public const int MAX_LENGTH = 48;

    private function __construct(
        private string $pattern,
    ) {
    }

    public static function fromPattern(string $pattern): self
    {
        $normalized = trim($pattern);

        if ('' === $normalized) {
            throw new DomainException('VHost pattern cannot be empty.');
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            throw new DomainException('VHost pattern exceeds maximum length of ' . self::MAX_LENGTH . ' characters.');
        }

        if (1 !== preg_match(self::VHOST_PATTERN, $normalized)) {
            throw new DomainException('Invalid vhost pattern format. Valid characters: a-z, A-Z, 0-9, hyphen (-) and dot (.). Must have at least one dot, cannot start/end with - or .');
        }

        return new self($normalized);
    }

    public static function isValidPattern(?string $pattern): bool
    {
        if (null === $pattern || '' === trim($pattern)) {
            return false;
        }

        $normalized = trim($pattern);

        if (strlen($normalized) > self::MAX_LENGTH) {
            return false;
        }

        return 1 === preg_match(self::VHOST_PATTERN, $normalized);
    }

    public function generateVhost(string $nickname): string
    {
        $cleanNick = self::cleanNickname($nickname);

        return $cleanNick . '.' . $this->pattern;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public static function cleanNickname(string $nickname): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9.\-]/', '', $nickname);

        if ('' === $cleaned || null === $cleaned) {
            return 'user';
        }

        $cleaned = preg_replace('/^[.\-]+/', '', $cleaned);
        $cleaned = preg_replace('/[.\-]+$/', '', $cleaned);
        $cleaned = preg_replace('/\.\./', '.', $cleaned);
        $cleaned = preg_replace('/--/', '-', $cleaned);

        if ('' === $cleaned) {
            return 'user';
        }

        return $cleaned;
    }
}
