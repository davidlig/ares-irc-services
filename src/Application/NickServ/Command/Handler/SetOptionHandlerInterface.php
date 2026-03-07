<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;

/**
 * Handles one SET option (PASSWORD, EMAIL, LANGUAGE, PRIVATE, VHOST).
 * SetCommand delegates to these handlers; account is always non-null when called.
 */
interface SetOptionHandlerInterface
{
    public function handle(NickServContext $context, RegisteredNick $account, string $value): void;
}
