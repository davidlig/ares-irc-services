<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Domain\Entity\RegisteredNick;

/**
 * Handles one SET option (PASSWORD, EMAIL, LANGUAGE, PRIVATE, VHOST).
 * SetCommand delegates to these handlers; account is always non-null when called.
 */
interface SetOptionHandlerInterface
{
    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void;
}
