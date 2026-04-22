<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Application\Port\UidResolverInterface;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Uid;

final readonly class NetworkUidResolver implements UidResolverInterface
{
    public function __construct(
        private NetworkUserRepositoryInterface $userRepository,
    ) {
    }

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
