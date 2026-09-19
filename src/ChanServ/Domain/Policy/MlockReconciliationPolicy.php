<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeCapability;
use App\ChanServ\Domain\ValueObject\ModeChange;
use App\ChanServ\Domain\ValueObject\ModeChangeAction;

use function array_key_exists;

final readonly class MlockReconciliationPolicy
{
    /**
     * @param list<ChannelSetting> $currentSettings
     * @param list<ModeCapability> $capabilities
     *
     * @return list<ModeChange>
     */
    public function reconcile(ChannelModeLock $lock, array $currentSettings, array $capabilities): array
    {
        if (!$lock->active) {
            return [];
        }

        $supported = [];
        foreach ($capabilities as $capability) {
            $supported[$capability->mode->value] = $capability;
        }

        $current = [];
        foreach ($currentSettings as $setting) {
            $current[$setting->mode->value] = $setting;
        }

        $changes = [];
        foreach ($current as $name => $setting) {
            $capability = $supported[$name] ?? null;
            if (null === $capability || $capability->protected || null !== $lock->setting($setting->mode)) {
                continue;
            }
            if ($capability->parameterRequiredWhenUnset && null === $setting->parameter) {
                continue;
            }

            $changes[] = new ModeChange(
                $setting->mode,
                ModeChangeAction::Remove,
                $capability->parameterRequiredWhenUnset ? $setting->parameter : null,
            );
        }

        foreach ($lock->settings as $setting) {
            $name = $setting->mode->value;
            $capability = $supported[$name] ?? null;
            if (null === $capability || $capability->protected || array_key_exists($name, $current)) {
                continue;
            }
            if ($capability->parameterRequiredWhenSet && null === $setting->parameter) {
                continue;
            }

            $changes[] = new ModeChange(
                $setting->mode,
                ModeChangeAction::Add,
                $capability->parameterRequiredWhenSet ? $setting->parameter : null,
            );
        }

        return $changes;
    }
}
