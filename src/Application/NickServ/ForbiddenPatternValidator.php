<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Domain\NickServ\Entity\ForbiddenVhost;

use function preg_match;
use function strlen;
use function trim;

/**
 * Validates forbidden vhost patterns.
 * Allows wildcards: * matches any sequence, ? matches single character.
 */
final readonly class ForbiddenPatternValidator
{
    private const string LABEL_PART = '[a-zA-Z0-9*?]+(-[a-zA-Z0-9*?]+)*';

    private const string PATTERN_REGEX = '/^[a-zA-Z0-9*?]+(-[a-zA-Z0-9*?]+)*(\\.[a-zA-Z0-9*?]+(-[a-zA-Z0-9*?]+)*)*$/';

    public function isValid(?string $pattern): bool
    {
        $normalized = null !== $pattern ? trim($pattern) : '';

        $result = match (true) {
            '' === $normalized => false,
            strlen($normalized) > ForbiddenVhost::MAX_PATTERN_LENGTH => false,
            default => 1 === preg_match(self::PATTERN_REGEX, $normalized),
        };

        return $result;
    }
}
