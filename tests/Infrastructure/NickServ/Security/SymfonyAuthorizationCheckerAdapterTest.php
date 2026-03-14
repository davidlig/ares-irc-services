<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security;

use App\Infrastructure\NickServ\Security\SymfonyAuthorizationCheckerAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface as SymfonyAuthorizationCheckerInterface;

#[CoversClass(SymfonyAuthorizationCheckerAdapter::class)]
final class SymfonyAuthorizationCheckerAdapterTest extends TestCase
{
    private SymfonyAuthorizationCheckerInterface&MockObject $symfonyChecker;

    private SymfonyAuthorizationCheckerAdapter $adapter;

    protected function setUp(): void
    {
        $this->symfonyChecker = $this->createMock(SymfonyAuthorizationCheckerInterface::class);
        $this->adapter = new SymfonyAuthorizationCheckerAdapter($this->symfonyChecker);
    }

    #[Test]
    public function isGrantedDelegatesToSymfonyCheckerAndReturnsResult(): void
    {
        $this->symfonyChecker->expects(self::once())
            ->method('isGranted')
            ->with('ROLE_USER', null)
            ->willReturn(true);

        self::assertTrue($this->adapter->isGranted('ROLE_USER', null));
    }
}
