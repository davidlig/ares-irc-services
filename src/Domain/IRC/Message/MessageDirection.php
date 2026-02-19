<?php

declare(strict_types=1);

namespace App\Domain\IRC\Message;

enum MessageDirection
{
    case Incoming;
    case Outgoing;
}
