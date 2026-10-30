<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

interface StyleProvider
{
    public function current(): PresentationStyle;
}
