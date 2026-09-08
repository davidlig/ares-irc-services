<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Voter;

use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Application\Security\IrcopAuthorizationSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

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
 * 3. User's role has the required permission
 *
 * @extends Voter<string, IrcopAuthorizationSubject>
 */
final class IrcopPermissionVoter extends Voter
{
    public function __construct(private readonly OperatorAuthorizationQuery $authorization) {}

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
        $nickname = $subject->getSenderNickname();
        if (null === $nickname) {
            return false;
        }

        return $this->authorization->permission(new OperatorActor(
            nickname: $nickname,
            identifiedAccountId: $subject->getSenderAccountId(),
            identified: $subject->isSenderIdentified(),
            ircOperator: $subject->isSenderIrcOperator(),
        ), $attribute)->granted;
    }
}
