<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Userip;

final readonly class GetUseripHandler implements GetUseripHandlerInterface
{
    public function handle(GetUserip $command): GetUseripResult
    {
        if (null === $command->ip || null === $command->hostname) {
            return GetUseripResult::notOnline($command->nickname);
        }

        return GetUseripResult::success(
            nickname: $command->nickname,
            ip: $command->ip,
            host: $command->hostname,
        );
    }
}
