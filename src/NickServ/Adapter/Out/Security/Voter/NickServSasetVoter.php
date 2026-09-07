<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security\Voter;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\Out\Security\IrcServiceUser;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Security\NickServPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;
use function strtolower;

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
        private readonly NickServOperatorAccess $operatorAccess,
    ) {}

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
        // @phpstan-ignore identical.alwaysFalse
        if (null === $sender) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $senderNickLower = strtolower($sender->nick);
        $account = $subject->senderAccount;

        return ($sender->isIdentified && $this->operatorAccess->isRoot($senderNickLower))
            || (
                in_array(IrcServiceUser::ROLE_OPER, $user->getRoles(), true)
                && null !== $account
                && $this->operatorAccess->hasPermission($account->getId(), $senderNickLower, NickServPermission::SASET)
            );
    }
}
