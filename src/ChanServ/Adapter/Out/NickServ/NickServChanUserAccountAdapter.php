<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\NickServ;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;

final readonly class NickServChanUserAccountAdapter implements ChanUserAccountPort
{
    public function __construct(private NickAccountQuery $nickAccountQuery) {}

    public function findAccountByNick(string $nickname): ?ChanAccountView
    {
        $account = $this->nickAccountQuery->findAccountByNick($nickname);
        if (null === $account) {
            return null;
        }

        return $this->toView($account);
    }

    public function findAccountById(int $id): ?ChanAccountView
    {
        $account = $this->nickAccountQuery->findAccountById($id);
        if (null === $account) {
            return null;
        }

        return $this->toView($account);
    }

    public function findIdByNick(string $nickname): ?int
    {
        return $this->nickAccountQuery->findIdByNick($nickname);
    }

    public function findNicknameById(int $id): ?string
    {
        return $this->nickAccountQuery->findNicknameById($id);
    }

    private function toView(NickAccountData $data): ChanAccountView
    {
        return new ChanAccountView(
            id: $data->id,
            nickname: $data->nickname,
            language: $data->language,
            timezone: $data->timezone,
            registered: $data->registered,
            suspended: $data->suspended,
            email: $data->email,
        );
    }
}
