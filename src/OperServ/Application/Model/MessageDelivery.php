<?php

declare(strict_types=1);

namespace App\OperServ\Application\Model;

/** Semantic delivery intent; concrete IRC commands are selected by adapters. */
enum MessageDelivery: string
{
    case NonInteractive = 'non_interactive';
    case Interactive = 'interactive';
}
