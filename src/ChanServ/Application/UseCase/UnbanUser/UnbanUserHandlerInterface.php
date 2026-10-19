<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UnbanUser;

interface UnbanUserHandlerInterface
{
    public function handle(UnbanUser $command): UnbanUserResult;
}
