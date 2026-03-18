<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\OperServ\RootUserRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RootUserRegistry::class)]
final class RootUserRegistryTest extends TestCase
{
    #[Test]
    public function isRootReturnsFalseForEmptyString(): void
    {
        $registry = new RootUserRegistry('');
        self::assertFalse($registry->isRoot('Admin'));
        self::assertFalse($registry->isRoot('Root'));
    }

    #[Test]
    public function isRootReturnsFalseForAnyNickWhenNoRootUsers(): void
    {
        $registry = new RootUserRegistry('');
        self::assertFalse($registry->isRoot('SomeNick'));
        self::assertFalse($registry->isRoot('AnotherNick'));
    }

    #[Test]
    public function isRootReturnsTrueForSingleRootUser(): void
    {
        $registry = new RootUserRegistry('Admin');
        self::assertTrue($registry->isRoot('Admin'));
    }

    #[Test]
    public function isRootReturnsFalseForNonRootNick(): void
    {
        $registry = new RootUserRegistry('Admin');
        self::assertFalse($registry->isRoot('User'));
        self::assertFalse($registry->isRoot('Guest'));
    }

    #[Test]
    public function isRootIsCaseInsensitive(): void
    {
        $registry = new RootUserRegistry('RootUser');
        self::assertTrue($registry->isRoot('RootUser'));
        self::assertTrue($registry->isRoot('rootuser'));
        self::assertTrue($registry->isRoot('ROOTUSER'));
        self::assertTrue($registry->isRoot('rOOtUsEr'));
    }

    #[Test]
    public function isRootWorksWithMultipleRootUsers(): void
    {
        $registry = new RootUserRegistry('Admin,Operator,Root');
        self::assertTrue($registry->isRoot('Admin'));
        self::assertTrue($registry->isRoot('Operator'));
        self::assertTrue($registry->isRoot('Root'));
        self::assertFalse($registry->isRoot('User'));
    }

    #[Test]
    public function isRootHandlesWhitespaceAroundNicks(): void
    {
        $registry = new RootUserRegistry(' Admin , Operator , Root ');
        self::assertTrue($registry->isRoot('Admin'));
        self::assertTrue($registry->isRoot('Operator'));
        self::assertTrue($registry->isRoot('Root'));
    }

    #[Test]
    public function getRootNicksReturnsEmptyArrayForEmptyString(): void
    {
        $registry = new RootUserRegistry('');
        self::assertSame([], $registry->getRootNicks());
    }

    #[Test]
    public function getRootNicksReturnsSingleNickInLowercase(): void
    {
        $registry = new RootUserRegistry('Admin');
        self::assertSame(['admin'], $registry->getRootNicks());
    }

    #[Test]
    public function getRootNicksReturnsAllNicksInLowercase(): void
    {
        $registry = new RootUserRegistry('Admin,Operator,Root');
        self::assertSame(['admin', 'operator', 'root'], $registry->getRootNicks());
    }

    #[Test]
    public function getRootNicksPreservesOrder(): void
    {
        $registry = new RootUserRegistry('First,Second,Third');
        self::assertSame(['first', 'second', 'third'], $registry->getRootNicks());
    }

    #[Test]
    public function getRootNicksHandlesWhitespace(): void
    {
        $registry = new RootUserRegistry(' Admin , Operator , Root ');
        self::assertSame(['admin', 'operator', 'root'], $registry->getRootNicks());
    }

    #[Test]
    public function caseInsensitiveMatchingWithMixedCaseInput(): void
    {
        $registry = new RootUserRegistry('RoOtUsEr');
        self::assertTrue($registry->isRoot('rootuser'));
        self::assertTrue($registry->isRoot('ROOTUSER'));
        self::assertTrue($registry->isRoot('RootUser'));
    }
}
