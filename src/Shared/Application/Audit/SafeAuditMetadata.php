<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use InvalidArgumentException;

use function in_array;
use function is_scalar;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

final class SafeAuditMetadata
{
    private const array SENSITIVE_TERMS = ['password', 'token', 'secret', 'credential'];

    private const array RAW_PAYLOAD_KEYS = [
        'args',
        'arguments',
        'commandargs',
        'commandobject',
        'payload',
        'rawargs',
        'rawarguments',
        'rawline',
    ];

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, bool|float|int|string|null>
     */
    public static function validate(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || '' === trim($key)) {
                throw new InvalidArgumentException('Command audit metadata keys must be non-empty strings.');
            }

            if (!is_scalar($value) && null !== $value) {
                throw new InvalidArgumentException(sprintf('Command audit metadata "%s" must be scalar or null.', $key));
            }

            $normalizedKey = self::normalize($key);
            if (self::containsSensitiveTerm($normalizedKey)) {
                throw new InvalidArgumentException(sprintf('Sensitive command audit metadata key "%s" is forbidden.', $key));
            }

            if (in_array($normalizedKey, self::RAW_PAYLOAD_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('Raw command audit metadata key "%s" is forbidden.', $key));
            }

            $safe[$key] = $value;
        }

        self::assertSensitiveOptionHasNoValue($safe);

        return $safe;
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private static function assertSensitiveOptionHasNoValue(array $metadata): void
    {
        $option = self::findValue($metadata, 'option');
        if (!is_string($option) || !self::containsSensitiveTerm(self::normalize($option))) {
            return;
        }

        foreach ($metadata as $key => $value) {
            if (str_contains(self::normalize($key), 'value') && null !== $value && '' !== $value) {
                throw new InvalidArgumentException('Sensitive command audit options cannot include a value.');
            }
        }
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private static function findValue(array $metadata, string $normalizedName): bool|float|int|string|null
    {
        foreach ($metadata as $key => $value) {
            if ($normalizedName === self::normalize($key)) {
                return $value;
            }
        }

        return null;
    }

    private static function containsSensitiveTerm(string $value): bool
    {
        foreach (self::SENSITIVE_TERMS as $term) {
            if (str_contains($value, $term)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }
}
