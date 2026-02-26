<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security;

use App\Application\Port\SenderView;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adapts SenderView to Symfony UserInterface for use in Security tokens.
 * Roles are derived from IRC modes: ROLE_IDENTIFIED (+r), ROLE_OPER (+o).
 */
final readonly class IrcServiceUser implements UserInterface
{
    public const string ROLE_USER = 'ROLE_USER';

    public const string ROLE_IDENTIFIED = 'ROLE_IDENTIFIED';

    public const string ROLE_OPER = 'ROLE_OPER';

    public function __construct(
        private SenderView $senderView,
    ) {
    }

    public function getRoles(): array
    {
        $roles = [self::ROLE_USER];

        if ($this->senderView->isIdentified) {
            $roles[] = self::ROLE_IDENTIFIED;
        }

        if ($this->senderView->isOper) {
            $roles[] = self::ROLE_OPER;
        }

        return $roles;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->senderView->uid;
    }

    public function getSenderView(): SenderView
    {
        return $this->senderView;
    }
}
