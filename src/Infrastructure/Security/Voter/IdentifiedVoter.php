<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Voter;

use App\Application\Security\IrcopContextInterface;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Grants access when the user is identified (+r) with NickServ.
 * Used for commands that require nickname identification.
 */
final class IdentifiedVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'IDENTIFIED' === $attribute && $subject instanceof IrcopContextInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof IrcServiceUser) {
            return false;
        }

        return in_array(IrcServiceUser::ROLE_IDENTIFIED, $user->getRoles(), true);
    }
}
