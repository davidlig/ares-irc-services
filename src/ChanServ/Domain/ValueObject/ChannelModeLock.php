<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

final readonly class ChannelModeLock
{
    /** @param list<ChannelSetting> $settings */
    private function __construct(
        public bool $active,
        public array $settings,
    ) {}

    public static function inactive(): self
    {
        return new self(false, []);
    }

    /** @param list<ChannelSetting> $settings */
    public static function active(array $settings = []): self
    {
        return new self(true, self::unique($settings));
    }

    public function setting(ModeName $mode): ?ChannelSetting
    {
        foreach ($this->settings as $setting) {
            if ($setting->mode->value === $mode->value) {
                return $setting;
            }
        }

        return null;
    }

    /**
     * @param list<ChannelSetting> $settings
     *
     * @return list<ChannelSetting>
     */
    private static function unique(array $settings): array
    {
        $unique = [];
        foreach ($settings as $setting) {
            $unique[$setting->mode->value] = $setting;
        }

        return array_values($unique);
    }
}
