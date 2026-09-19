<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence;

use App\ChanServ\Application\Port\Out\ChannelMlockStorage;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;

final readonly class DoctrineChannelMlockStorage implements ChannelMlockStorage
{
    public function store(RegisteredChannel $channel, ChannelModeLock $modeLock): void
    {
        $letters = [];
        $parameters = [];
        foreach ($modeLock->settings as $setting) {
            $letters[] = $setting->mode->value;
            if (null !== $setting->parameter && '' !== $setting->parameter) {
                $parameters[$setting->mode->value] = $setting->parameter;
            }
        }

        $channel->configureMlock(
            $modeLock->active,
            [] === $letters ? '' : '+' . implode('', $letters),
            $parameters,
        );
    }
}
