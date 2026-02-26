<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use function preg_match;
use function strlen;
use function trim;

/**
 * Validates and normalizes vhost (user part only, without suffix).
 * Rules: a-z A-Z 0-9; hyphen and dot allowed but not at start/end of string or of any label.
 * Max length: 48 characters.
 */
final readonly class VhostValidator
{
    /** Max length for the user-chosen vhost part (suffix is appended for display). */
    public const int MAX_LENGTH = 48;

    /**
     * Each label: alphanumeric, optional internal hyphens. Labels separated by dots.
     * No leading/trailing dot or hyphen on the whole string or on any label.
     */
    private const string LABEL_PATTERN = '[a-zA-Z0-9]+(-[a-zA-Z0-9]+)*';

    private const string VHOST_PATTERN = '/^' . self::LABEL_PATTERN . '(\\.' . self::LABEL_PATTERN . ')*$/';

    /**
     * Returns normalized vhost (trimmed) or null if invalid.
     */
    public function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = trim($value);
        if ('' === $normalized) {
            return null;
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            return null;
        }

        if (1 !== preg_match(self::VHOST_PATTERN, $normalized)) {
            return null;
        }

        return $normalized;
    }

    public function isValid(?string $value): bool
    {
        return null !== $this->normalize($value);
    }
}
