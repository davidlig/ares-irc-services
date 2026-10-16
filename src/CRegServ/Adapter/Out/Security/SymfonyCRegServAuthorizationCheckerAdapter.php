<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\Security;

use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface as SymfonyAuthorizationCheckerInterface;

final readonly class SymfonyCRegServAuthorizationCheckerAdapter implements CRegServAuthorizationCheckerInterface
{
    public function __construct(
        private SymfonyAuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function isGranted(string $permission, mixed $subject = null): bool
    {
        return $this->authorizationChecker->isGranted($permission, $subject);
    }
}
