<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\Uid;
use App\Shared\Application\Port\UidResolverInterface;

final readonly class NetworkUidResolver implements UidResolverInterface
{
    public function __construct(
        private NetworkUserRepositoryInterface $userRepository,
    ) {}

    public function resolveUidToNick(string $uid): ?string
    {
        if (!preg_match('/^[0-9][0-9A-Z]{5,}$/', $uid)) {
            return null;
        }

        $user = $this->userRepository->findByUid(new Uid($uid));
        if (null === $user) {
            return null;
        }

        return $user->getNick()->value;
    }
}
