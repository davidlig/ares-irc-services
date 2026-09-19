<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security\Voter;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\IdentifiedAccountOwnerPolicy;
use App\NickServ\Application\Security\NickServPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access when the sender is identified (+r) and is the owner of the account
 * (sender's nick matches the account nickname). Used for SET and other owner-only commands.
 */
/**
 * @extends Voter<string, NickServContext>
 */
final class NickServIdentifiedOwnerVoter extends Voter
{
    public function __construct(private readonly IdentifiedAccountOwnerPolicy $policy) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return NickServPermission::IDENTIFIED_OWNER === $attribute && $subject instanceof NickServContext;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $sender = $subject->sender;
        $account = $subject->senderAccount;

        if (null === $sender || null === $account) {
            return false;
        }

        return $this->policy->allows(
            identified: $sender->isIdentified,
            actorAccountId: $subject->getSenderAccountId(),
            ownerAccountId: $account->getId(),
        );
    }
}
