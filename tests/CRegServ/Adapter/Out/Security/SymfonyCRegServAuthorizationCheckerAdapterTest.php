<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\Out\Security;

use App\CRegServ\Adapter\Out\Security\SymfonyCRegServAuthorizationCheckerAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(SymfonyCRegServAuthorizationCheckerAdapter::class)]
final class SymfonyCRegServAuthorizationCheckerAdapterTest extends TestCase
{
    #[Test]
    public function isGrantedDelegatesToSymfony(): void
    {
        $symfonyChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $symfonyChecker->expects(self::once())
            ->method('isGranted')
            ->with('cregserv.aprobar', 'subject')
            ->willReturn(true);

        $adapter = new SymfonyCRegServAuthorizationCheckerAdapter($symfonyChecker);

        self::assertTrue($adapter->isGranted('cregserv.aprobar', 'subject'));
    }

    #[Test]
    public function isGrantedForwardsANullSubject(): void
    {
        $symfonyChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $symfonyChecker->expects(self::once())
            ->method('isGranted')
            ->with('cregserv.drop', null)
            ->willReturn(false);

        $adapter = new SymfonyCRegServAuthorizationCheckerAdapter($symfonyChecker);

        self::assertFalse($adapter->isGranted('cregserv.drop'));
    }
}
