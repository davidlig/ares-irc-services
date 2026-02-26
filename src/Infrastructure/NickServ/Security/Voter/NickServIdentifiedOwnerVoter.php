<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security\Voter;

use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function assert;

/**
 * Grants access when the sender is identified (+r) and is the owner of the account
 * (sender's nick matches the account nickname). Used for SET and other owner-only commands.
 */
final class NickServIdentifiedOwnerVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return NickServPermission::IDENTIFIED_OWNER === $attribute && $subject instanceof NickServContext;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        assert($subject instanceof NickServContext);

        $sender = $subject->sender;
        $account = $subject->senderAccount;

        if (null === $sender || null === $account) {
            return false;
        }

        if (!$sender->isIdentified) {
            return false;
        }

        return 0 === strcasecmp($sender->nick, $account->getNickname());
    }
}
