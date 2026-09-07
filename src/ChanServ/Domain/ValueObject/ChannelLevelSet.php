<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ChannelLevelSet
{
    /** @param array<string, int> $overrides */
    private function __construct(private array $overrides) {}

    public static function defaults(): self
    {
        return new self([]);
    }

    /** @param array<string, int> $overrides */
    public static function fromOverrides(array $overrides): self
    {
        $validated = [];
        foreach ($overrides as $key => $value) {
            $level = ChannelLevel::tryFrom($key);
            if (null === $level) {
                throw new InvalidArgumentException('Unknown channel level: ' . $key);
            }
            self::assertValidValue($value);
            $validated[$level->value] = $value;
        }

        return new self($validated);
    }

    public function valueFor(ChannelLevel $level): int
    {
        return $this->overrides[$level->value] ?? $level->defaultValue();
    }

    public function withOverride(ChannelLevel $level, int $value): self
    {
        self::assertValidValue($value);
        $overrides = $this->overrides;
        $overrides[$level->value] = $value;

        return new self($overrides);
    }

    public function withoutOverride(ChannelLevel $level): self
    {
        $overrides = $this->overrides;
        unset($overrides[$level->value]);

        return new self($overrides);
    }

    /** @return array<string, int> */
    public function overrides(): array
    {
        return $this->overrides;
    }

    private static function assertValidValue(int $value): void
    {
        if (-1 > $value || 499 < $value) {
            throw new InvalidArgumentException('Channel level must be between -1 and 499.');
        }
    }
}
