<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\NickServ;

use App\ChanServ\Application\Port\Out\IdentifiedRequesterSession;
use App\NickServ\Application\Port\In\IdentifiedSessionQuery;

final readonly class NickServIdentifiedRequesterSession implements IdentifiedRequesterSession
{
    public function __construct(private IdentifiedSessionQuery $sessions) {}

    public function isIdentifiedAs(string $uid, string $registeredNickname): bool
    {
        return $this->sessions->isIdentifiedAs($uid, $registeredNickname);
    }
}
