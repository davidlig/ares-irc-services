<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\List;

interface ListNickAccountsHandlerInterface
{
    public function handle(ListNickAccounts $query): ListNickAccountsResult;
}
