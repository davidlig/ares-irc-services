<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security\Voter;

use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;
use function strtolower;

/**
 * Grants access for SASET command when:
 * User is an IRCop with 'nickserv.saset' permission.
 * Root users always have access.
 */
final class NickServSasetVoter extends Voter
{
    public function __construct(
        private readonly IrcopAccessHelper $accessHelper,
        private readonly RootUserRegistry $rootRegistry,
        private readonly OperIrcopRepositoryInterface $ircopRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return NickServPermission::SASET === $attribute && $subject instanceof NickServContext;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof IrcServiceUser) {
            return false;
        }

        $sender = $user->getSenderView();

        // @codeCoverageIgnoreStart
        // Defensive: IrcServiceUser is always created with a valid SenderView
        if (null === $sender) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $senderNickLower = strtolower($sender->nick);

        // Root users have all permissions
        if ($sender->isIdentified && $this->rootRegistry->isRoot($senderNickLower)) {
            return true;
        }

        // Must have ROLE_OPER and permission
        if (!in_array(IrcServiceUser::ROLE_OPER, $user->getRoles(), true)) {
            return false;
        }

        $account = $subject->senderAccount;
        if (null === $account) {
            return false;
        }

        return $this->accessHelper->hasPermission(
            $account->getId(),
            $senderNickLower,
            NickServPermission::SASET
        );
    }
}
