<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Security\Voter;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, ChanServContext>
 */
final class ChanServLevelFounderVoter extends Voter
{
    public function __construct(
        private readonly OperatorAuthorizationQuery $authorization,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ChanServPermission::LEVEL_FOUNDER === $attribute && $subject instanceof ChanServContext;
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
        ), ChanServPermission::LEVEL_FOUNDER)->granted;
    }
}
