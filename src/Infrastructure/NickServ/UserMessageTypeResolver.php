<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ;

use App\Application\Port\SenderView;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * Resolves the preferred message type (NOTICE or PRIVMSG) for services to use when talking to a user.
 *
 * Resolution: if the user has a registered account, use the account's preference (SET MSG ON|OFF);
 * otherwise default to NOTICE.
 */
final readonly class UserMessageTypeResolver implements UserMessageTypeResolverInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    /**
     * @return 'NOTICE'|'PRIVMSG'
     */
    public function resolve(SenderView $sender): string
    {
        return $this->resolveByNick($sender->nick);
    }

    /**
     * @return 'NOTICE'|'PRIVMSG'
     */
    public function resolveByNick(string $nick): string
    {
        $account = $this->nickRepository->findByNick($nick);

        return null !== $account ? $account->getMessageType() : 'NOTICE';
    }
}
