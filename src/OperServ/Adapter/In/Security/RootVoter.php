<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Security;

use App\OperServ\Application\Port\In\IrcopAuthorizationSubject;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, IrcopAuthorizationSubject> */
final class RootVoter extends Voter
{
    public function __construct(private readonly OperatorAuthorizationQuery $authorization) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return OperatorAuthorizationAttribute::ROOT === $attribute && $subject instanceof IrcopAuthorizationSubject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $nickname = $subject->getSenderNickname();
        if (null === $nickname) {
            return false;
        }

        return $this->authorization->root(new OperatorActor(
            nickname: $nickname,
            identifiedAccountId: $subject->getSenderAccountId(),
            identified: $subject->isSenderIdentified(),
            ircOperator: $subject->isSenderIrcOperator(),
        ))->granted;
    }
}
