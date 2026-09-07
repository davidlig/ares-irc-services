<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Security\Voter;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Irc\Application\Port\In\SenderView;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;
use function strtolower;

/**
 * @extends Voter<string, ChanServContext>
 */
final class ChanServLevelFounderVoter extends Voter
{
    public function __construct(
        private readonly ChanServOperatorAccess $operatorAccess,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ChanServPermission::LEVEL_FOUNDER === $attribute && $subject instanceof ChanServContext;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface || !$subject->sender instanceof SenderView) {
            return false;
        }

        return $this->checkLevelFounder($subject->sender, $user, $subject);
    }

    private function checkLevelFounder(SenderView $sender, UserInterface $user, ChanServContext $subject): bool
    {
        $senderNickLower = strtolower($sender->nick);

        if ($sender->isIdentified && $this->operatorAccess->isRoot($senderNickLower)) {
            return true;
        }

        return $this->checkOperPermission($senderNickLower, $user, $subject);
    }

    private function checkOperPermission(string $senderNickLower, UserInterface $user, ChanServContext $subject): bool
    {
        if (!in_array('ROLE_OPER', $user->getRoles(), true)) {
            return false;
        }

        $account = $subject->senderAccount;
        if (null === $account) {
            return false;
        }

        return $this->operatorAccess->hasPermission(
            $account->id,
            $senderNickLower,
            ChanServPermission::LEVEL_FOUNDER,
        );
    }
}
