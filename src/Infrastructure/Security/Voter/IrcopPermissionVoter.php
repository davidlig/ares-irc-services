<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Voter;

use App\Application\OperServ\IrcopAccessHelper;
use App\NickServ\Adapter\Out\Security\IrcServiceUser;
use App\Shared\Application\Security\IrcopAuthorizationSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Grants access when the user is an IRCOP with a specific permission.
 *
 * This voter handles IRCOP-level permissions like operserv.kill, nickserv.drop, etc.
 *
 * Permission format: service.command or service.subcommand.action (lowercase with dots)
 * Examples: operserv.kill, nickserv.drop, chanserv.mode.lock
 *
 * Checks:
 * 1. Root users identified have all permissions automatically (bypass +o requirement)
 * 2. User has ROLE_OPER (is an IRC operator)
 * 3. User's role has the required permission (via IrcopAccessHelper)
 *
 * @extends Voter<string, IrcopAuthorizationSubject>
 */
final class IrcopPermissionVoter extends Voter
{
    public function __construct(private readonly IrcopAccessHelper $accessHelper) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Support IRCOP permission strings in format: service.command or service.subcommand.action
        // Examples: operserv.kill, nickserv.drop, chanserv.mode.lock
        // Must have a context to get user info
        $supports = 1 === preg_match('/^[a-z]+\.[a-z._]+$/', $attribute)
            && $subject instanceof IrcopAuthorizationSubject;

        return $supports;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof IrcServiceUser) {
            return false;
        }

        $sender = $user->getSenderView();
        $nickLower = strtolower($sender->nick);

        if ($sender->isIdentified && $this->accessHelper->isRoot($nickLower)) {
            return true;
        }

        return $this->evaluateOperPermission($user, $subject, $attribute, $nickLower);
    }

    private function evaluateOperPermission(IrcServiceUser $user, IrcopAuthorizationSubject $subject, string $attribute, string $nickLower): bool
    {
        if (!in_array(IrcServiceUser::ROLE_OPER, $user->getRoles(), true)) {
            return false;
        }

        $accountId = $subject->getSenderAccountId();

        if (null === $accountId) {
            return false;
        }

        return $this->accessHelper->hasPermission($accountId, $nickLower, $attribute);
    }
}
