<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Whoip;

interface FindNicknamesByLastConnectIpHandlerInterface
{
    /** @return list<string> */
    public function handle(FindNicknamesByLastConnectIp $query): array;
}
