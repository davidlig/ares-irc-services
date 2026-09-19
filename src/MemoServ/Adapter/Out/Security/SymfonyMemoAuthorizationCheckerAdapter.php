<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\Security;

use App\MemoServ\Adapter\In\Irc\MemoAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface as SymfonyAuthorizationCheckerInterface;

final readonly class SymfonyMemoAuthorizationCheckerAdapter implements MemoAuthorizationCheckerInterface
{
    public function __construct(
        private SymfonyAuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function isGranted(string $permission, mixed $subject = null): bool
    {
        return $this->authorizationChecker->isGranted($permission, $subject);
    }
}
