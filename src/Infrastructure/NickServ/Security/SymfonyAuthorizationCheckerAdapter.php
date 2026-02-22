<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security;

use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface as SymfonyAuthorizationCheckerInterface;

/**
 * Adapts Symfony AuthorizationChecker to our Application interface.
 */
final readonly class SymfonyAuthorizationCheckerAdapter implements AuthorizationCheckerInterface
{
    public function __construct(
        private SymfonyAuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function isGranted(string $attribute, mixed $subject = null): bool
    {
        return $this->authorizationChecker->isGranted($attribute, $subject);
    }
}
