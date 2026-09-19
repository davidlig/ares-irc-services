<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Noexpire;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

final readonly class SetNoexpireNickHandler implements SetNoexpireNickHandlerInterface
{
    public function __construct(private RegisteredNickRepositoryInterface $nickRepository) {}

    public function handle(SetNoexpireNick $command): SetNoexpireNickResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account) {
            return SetNoexpireNickResult::notRegistered($command->nickname);
        }

        if ($account->isForbidden()) {
            return SetNoexpireNickResult::forbidden($command->nickname);
        }

        if ($account->isSuspended()) {
            return SetNoexpireNickResult::suspended($command->nickname);
        }

        $account->changeNoExpire($command->noexpire);
        $this->nickRepository->save($account);

        return SetNoexpireNickResult::success($command->nickname, $command->noexpire);
    }
}
