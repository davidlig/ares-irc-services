<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Security;

use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Application\Security\IrcopAuthorizationSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access when the user is identified (+r) with NickServ.
 * Used for commands that require nickname identification.
 *
 * @extends Voter<string, IrcopAuthorizationSubject>
 */
final class IdentifiedVoter extends Voter
{
    public function __construct(private readonly OperatorAuthorizationQuery $authorization) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return OperatorAuthorizationAttribute::IDENTIFIED === $attribute && $subject instanceof IrcopAuthorizationSubject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $nickname = $subject->getSenderNickname();
        if (null === $nickname) {
            return false;
        }

        return $this->authorization->identifiedAccount(new OperatorActor(
            nickname: $nickname,
            identifiedAccountId: $subject->getSenderAccountId(),
            identified: $subject->isSenderIdentified(),
            ircOperator: $subject->isSenderIrcOperator(),
        ))->granted;
    }
}
