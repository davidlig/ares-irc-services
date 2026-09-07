<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Voter;

use App\NickServ\Adapter\Out\Security\IrcServiceUser;
use App\Shared\Application\Security\IrcopAuthorizationSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Grants access when the user is identified (+r) with NickServ.
 * Used for commands that require nickname identification.
 *
 * @extends Voter<string, IrcopAuthorizationSubject>
 */
final class IdentifiedVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'IDENTIFIED' === $attribute && $subject instanceof IrcopAuthorizationSubject;
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
