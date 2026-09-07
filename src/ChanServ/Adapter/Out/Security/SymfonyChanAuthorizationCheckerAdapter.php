<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Security;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface as SymfonyAuthorizationCheckerInterface;

final readonly class SymfonyChanAuthorizationCheckerAdapter implements ChanAuthorizationCheckerInterface
{
    public function __construct(
        private SymfonyAuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function isGranted(string $permission, mixed $subject = null): bool
    {
        return $this->authorizationChecker->isGranted($permission, $subject);
    }
}
