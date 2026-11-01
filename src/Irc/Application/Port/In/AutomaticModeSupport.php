<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/** Public capability for ordinary-user automatic mode preferences. */
interface AutomaticModeSupport
{
    /**
     * Null means that network support is not yet known.
     *
     * @return array<string, string>|null Semantic intention => current mode letter.
     */
    public function getAutomaticModes(): ?array;
}
