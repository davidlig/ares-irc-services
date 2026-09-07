<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Security;

use App\ChanServ\Adapter\Out\Security\SymfonyChanAuthorizationCheckerAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(SymfonyChanAuthorizationCheckerAdapter::class)]
final class SymfonyChanAuthorizationCheckerAdapterTest extends TestCase
{
    #[Test]
    public function isGrantedDelegatesToSymfony(): void
    {
        $symfonyChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $symfonyChecker->expects(self::once())
            ->method('isGranted')
            ->with('IDENTIFIED', 'subject')
            ->willReturn(true);

        $adapter = new SymfonyChanAuthorizationCheckerAdapter($symfonyChecker);

        self::assertTrue($adapter->isGranted('IDENTIFIED', 'subject'));
    }
}
