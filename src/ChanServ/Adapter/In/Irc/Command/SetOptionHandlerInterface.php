<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Domain\Entity\RegisteredChannel;

/**
 * Handles one ChanServ SET option (DESC, URL, EMAIL, ENTRYMSG, TOPICLOCK, MLOCK, SECURE, SUCCESSOR, FOUNDER).
 * SetCommand delegates to these handlers; channel and permissions are already validated.
 */
interface SetOptionHandlerInterface
{
    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void;
}
