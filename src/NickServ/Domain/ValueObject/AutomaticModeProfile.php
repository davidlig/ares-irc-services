<?php

declare(strict_types=1);

namespace App\NickServ\Domain\ValueObject;

use InvalidArgumentException;

use function array_diff;
use function array_unique;
use function array_values;
use function sort;

final readonly class AutomaticModeProfile
{
    private const array INTENTIONS = [
        'invisible', 'wallops', 'bot', 'common-channel-only', 'channel-deaf',
        'private-deaf', 'caller-id', 'hide-channels', 'anti-redirect', 'no-history',
        'registered-only', 'strip-formatting', 'no-ctcp', 'censor', 'tls-only',
    ];

    /** @var list<string> */
    public array $intentions;

    /** @param list<string> $intentions */
    public function __construct(array $intentions = [])
    {
        if ([] !== array_diff($intentions, self::INTENTIONS)) {
            throw new InvalidArgumentException('Unknown automatic-mode intention.');
        }
        $normalized = array_values(array_unique($intentions));
        sort($normalized);
        $this->intentions = $normalized;
    }
}
