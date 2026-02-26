<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use InvalidArgumentException;

/**
 * Core implements NetworkUserLookupPort: resolves a connected user by UID or nick
 * and returns a SenderView DTO for Services. No Domain\IRC entities leak to Services.
 *
 * fromNetworkUser() is used by Infrastructure (e.g. NickProtectionSubscriber) to
 * convert event payloads to SenderView when calling Application services.
 */
final readonly class CoreNetworkUserLookupAdapter implements NetworkUserLookupPort
{
    public function __construct(
        private NetworkUserRepositoryInterface $networkUserRepository,
    ) {
    }

    public function findByUid(string $uid): ?SenderView
    {
        try {
            $user = $this->networkUserRepository->findByUid(new Uid($uid));
        } catch (InvalidArgumentException) {
            return null;
        }

        return null === $user ? null : $this->fromNetworkUser($user);
    }

    public function findByNick(string $nick): ?SenderView
    {
        try {
            $user = $this->networkUserRepository->findByNick(new Nick($nick));
        } catch (InvalidArgumentException) {
            return null;
        }

        return null === $user ? null : $this->fromNetworkUser($user);
    }

    public function listConnectedUids(): array
    {
        $users = $this->networkUserRepository->all();

        return array_map(static fn (NetworkUser $u) => $u->uid->value, $users);
    }

    /** Used by Infrastructure when converting event payloads (NetworkUser) to SenderView. */
    public function fromNetworkUser(NetworkUser $user): SenderView
    {
        return new SenderView(
            uid: $user->uid->value,
            nick: $user->getNick()->value,
            ident: $user->ident->value,
            hostname: $user->hostname,
            cloakedHost: $user->cloakedHost,
            ipBase64: $user->ipBase64,
            isIdentified: $user->isIdentified(),
            isOper: $user->isOper(),
            serverSid: $user->serverSid,
        );
    }
}
