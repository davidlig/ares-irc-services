<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Security\Voter;

use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\Out\Security\IrcServiceUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;
use function strtolower;

/**
 * @extends Voter<string, ChanServContext>
 */
final class ChanServLevelFounderVoter extends Voter
{
    public function __construct(
        private readonly IrcopAccessHelper $accessHelper,
        private readonly RootUserRegistry $rootRegistry,
        private readonly OperIrcopRepositoryInterface $ircopRepository,
    ) {}

    public function getIrcopRepository(): OperIrcopRepositoryInterface
    {
        return $this->ircopRepository;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ChanServPermission::LEVEL_FOUNDER === $attribute && $subject instanceof ChanServContext;
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

        $result = $this->checkLevelFounder($sender, $user, $subject);

        return $result;
    }

    private function checkLevelFounder(SenderView $sender, IrcServiceUser $user, ChanServContext $subject): bool
    {
        $senderNickLower = strtolower($sender->nick);

        if ($sender->isIdentified && $this->rootRegistry->isRoot($senderNickLower)) {
            return true;
        }

        $result = $this->checkOperPermission($senderNickLower, $user, $subject);

        return $result;
    }

    private function checkOperPermission(string $senderNickLower, IrcServiceUser $user, ChanServContext $subject): bool
    {
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
            ChanServPermission::LEVEL_FOUNDER
        );
    }
}
