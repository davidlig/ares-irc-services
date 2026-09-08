<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\NickServ;

use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\Out\OperatorAccountData;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;

final readonly class NickServOperatorAccountLookup implements OperatorAccountLookup
{
    public function __construct(private NickAccountQuery $accounts) {}

    public function findIdByNickname(string $nickname): ?int
    {
        return $this->accounts->findIdByNick($nickname);
    }

    public function findByNickname(string $nickname): ?OperatorAccountData
    {
        $account = $this->accounts->findAccountByNick($nickname);

        return null === $account ? null : new OperatorAccountData($account->id, $account->nickname, $account->registered);
    }

    public function findNicknameById(int $id): ?string
    {
        return $this->accounts->findNicknameById($id);
    }
}
