<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security\Voter;

use App\Application\NickServ\Security\NickServPermission;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Grants access when the token user has ROLE_OPER (IRC operator mode +o).
 * Used for oper-only NickServ/ChanServ commands.
 */
final class OperVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return NickServPermission::NETWORK_OPER === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof IrcServiceUser
            && in_array(IrcServiceUser::ROLE_OPER, $user->getRoles(), true);
    }
}
