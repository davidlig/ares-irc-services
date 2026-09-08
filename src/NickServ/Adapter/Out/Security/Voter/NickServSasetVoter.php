<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security\Voter;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access for SASET command when:
 * User is an IRCop with 'nickserv.saset' permission.
 * Root users always have access.
 */
/**
 * @extends Voter<string, NickServContext>
 */
final class NickServSasetVoter extends Voter
{
    public function __construct(
        private readonly OperatorAuthorizationQuery $authorization,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return NickServPermission::SASET === $attribute && $subject instanceof NickServContext;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $sender = $subject->sender;
        if (null === $sender) {
            return false;
        }

        return $this->authorization->permission(new OperatorActor(
            nickname: $sender->nick,
            identifiedAccountId: $subject->getSenderAccountId(),
            identified: $sender->isIdentified,
            ircOperator: $sender->isOper,
        ), NickServPermission::SASET)->granted;
    }
}
