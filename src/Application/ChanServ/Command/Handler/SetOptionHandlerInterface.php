<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\RegisteredChannel;

/**
 * Handles one ChanServ SET option (DESC, URL, EMAIL, ENTRYMSG, TOPICLOCK, MLOCK, SECURE, SUCCESSOR, FOUNDER).
 * SetCommand delegates to these handlers; channel and permissions are already validated.
 */
interface SetOptionHandlerInterface
{
    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void;
}
