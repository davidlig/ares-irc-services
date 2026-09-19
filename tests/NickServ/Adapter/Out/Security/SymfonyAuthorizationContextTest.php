<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\NickServ\Adapter\Out\Security\IrcServiceToken;
use App\NickServ\Adapter\Out\Security\SymfonyAuthorizationContext;
use App\NickServ\Application\Model\NetworkUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[CoversClass(SymfonyAuthorizationContext::class)]
final class SymfonyAuthorizationContextTest extends TestCase
{
    #[Test]
    public function setCurrentUserStoresIrcServiceToken(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $sender = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', false, false);

        $tokenStorage->expects(self::once())
            ->method('setToken')
            ->with(self::callback(static fn ($token): bool => $token instanceof IrcServiceToken));

        $context = new SymfonyAuthorizationContext($tokenStorage);
        $context->setCurrentUser($sender->uid, $sender->isIdentified, $sender->isOper);
    }

    #[Test]
    public function clearRemovesToken(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::once())->method('setToken')->with(null);

        $context = new SymfonyAuthorizationContext($tokenStorage);
        $context->clear();
    }
}
