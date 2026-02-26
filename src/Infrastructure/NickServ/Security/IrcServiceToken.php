<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security;

use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

use function sprintf;

/**
 * Token representing the current IRC user (SenderView) in the Security context.
 * Used when dispatching NickServ/ChanServ commands to run authorization checks.
 */
final class IrcServiceToken extends AbstractToken
{
    public function __construct(IrcServiceUser $user)
    {
        parent::__construct($user->getRoles());
        $this->setUser($user);
    }

    public function __toString(): string
    {
        $user = $this->getUser();

        return sprintf(
            'IrcServiceToken(user="%s", roles="%s")',
            $user instanceof IrcServiceUser ? $user->getUserIdentifier() : '',
            implode(', ', $this->getRoleNames()),
        );
    }
}
