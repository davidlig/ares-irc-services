<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Whoip;

final readonly class FindNicknamesByLastConnectIp
{
    public function __construct(public string $ip) {}
}
