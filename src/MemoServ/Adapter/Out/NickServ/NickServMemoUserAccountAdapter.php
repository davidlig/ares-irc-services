<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\NickServ;

use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\NickServ\Application\Port\In\NickAccountQuery;

final readonly class NickServMemoUserAccountAdapter implements MemoUserAccountPort
{
    public function __construct(
        private NickAccountQuery $nickAccountQuery,
    ) {}

    public function findAccountByNick(string $nickname): ?MemoAccountView
    {
        $account = $this->nickAccountQuery->findAccountByNick($nickname);
        if (null === $account) {
            return null;
        }

        return new MemoAccountView($account->id, $account->nickname, $account->language);
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
