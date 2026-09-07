<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\Security;

use App\MemoServ\Adapter\Out\Security\SymfonyMemoAuthorizationCheckerAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(SymfonyMemoAuthorizationCheckerAdapter::class)]
final class SymfonyMemoAuthorizationCheckerAdapterTest extends TestCase
{
    #[Test]
    public function isGrantedDelegatesToSymfony(): void
    {
        $symfonyChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $symfonyChecker->expects(self::once())
            ->method('isGranted')
            ->with('IDENTIFIED', 'subject')
            ->willReturn(true);

        $adapter = new SymfonyMemoAuthorizationCheckerAdapter($symfonyChecker);

        self::assertTrue($adapter->isGranted('IDENTIFIED', 'subject'));
    }
}
