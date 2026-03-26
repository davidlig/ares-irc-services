<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Voter;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\Security\IrcopContextInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Grants access when the user is an IRCOP with a specific permission.
 *
 * This voter handles IRCOP-level permissions like NICKSERV_DROP, CHANSERV_SUSPEND, etc.
 *
 * Checks:
 * 1. User has ROLE_OPER (is an IRC operator)
 * 2. User's role has the required permission (via IrcopAccessHelper)
 * 3. Root users have all permissions automatically
 */
final class IrcopPermissionVoter extends Voter
{
    public function __construct(
        private readonly IrcopAccessHelper $accessHelper,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Support permission constants that match the pattern BOT_COMMAND (uppercase with underscore)
        // Must have a context to get user info
        return 1 === preg_match('/^[A-Z]+_[A-Z_]+$/', $attribute)
            && $subject instanceof IrcopContextInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof IrcServiceUser) {
            return false;
        }

        $sender = $user->getSenderView();

        // Must have ROLE_OPER (be an IRC operator)
        if (!in_array(IrcServiceUser::ROLE_OPER, $user->getRoles(), true)) {
            return false;
        }

        // Root users have all permissions automatically
        if ($this->accessHelper->isRoot(strtolower($sender->nick))) {
            return true;
        }

        // Need account to check permissions
        $account = $subject->getSenderAccount();
        if (null === $account) {
            return false;
        }

        return $this->accessHelper->hasPermission(
            $account->getId(),
            strtolower($sender->nick),
            $attribute
        );
    }
}
