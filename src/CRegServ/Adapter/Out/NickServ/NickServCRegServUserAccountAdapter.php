<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\NickServ;

use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\Port\Out\CRegServUserAccountPort;
use App\NickServ\Application\Port\In\NickAccountQuery;

final readonly class NickServCRegServUserAccountAdapter implements CRegServUserAccountPort
{
    public function __construct(
        private NickAccountQuery $nickAccountQuery,
    ) {}

    public function findAccountByNick(string $nickname): ?CRegServAccountView
    {
        $account = $this->nickAccountQuery->findAccountByNick($nickname);
        if (null === $account) {
            return null;
        }

        return new CRegServAccountView($account->id, $account->nickname, $account->language);
    }

    public function findNicknameById(int $id): ?string
    {
        return $this->nickAccountQuery->findNicknameById($id);
    }

    public function getLanguage(int $id): string
    {
        return $this->nickAccountQuery->getLanguage($id);
    }
}
