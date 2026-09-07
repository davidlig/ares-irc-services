<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

/**
 * Resolves the preferred message type (NOTICE or PRIVMSG) for services to use when talking to a user.
 *
 * Resolution: if the user has a registered account, use the account's preference (SET MSG ON|OFF);
 * otherwise default to NOTICE.
 */
final readonly class UserMessageTypeResolver implements UserMessagePreferenceQuery
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {}

    public function prefersPrivateMessages(string $nickname): bool
    {
        $account = $this->nickRepository->findByNick($nickname);

        return null !== $account && $account->prefersPrivateMessages();
    }
}
