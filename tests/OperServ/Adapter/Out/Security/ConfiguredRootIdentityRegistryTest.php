<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Security;

use App\Irc\Domain\ValueObject\CaseMapping;
use App\OperServ\Adapter\Out\Security\ConfiguredRootIdentityRegistry;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredRootIdentityRegistry::class)]
final class ConfiguredRootIdentityRegistryTest extends TestCase
{
    #[Test]
    public function parsesConfiguredIdentitiesCaseInsensitivelyAndIgnoresEmptyItems(): void
    {
        $registry = new ConfiguredRootIdentityRegistry(' Root, SECOND ,, root ', NegotiatedCaseMapping::network());

        self::assertTrue($registry->contains('ROOT'));
        self::assertTrue($registry->contains('second'));
        self::assertFalse($registry->contains('other'));
        self::assertSame(['root', 'second'], $registry->allNicknames());
    }

    #[Test]
    public function rootNicknamesUseTheRfc1459FoldAndListAsciiLoweredNames(): void
    {
        $registry = new ConfiguredRootIdentityRegistry('[Root]\\, {ROOT}|, Admin', NegotiatedCaseMapping::network());

        self::assertTrue($registry->contains('{root}|'));
        self::assertTrue($registry->contains('ADMIN'));
        self::assertFalse($registry->contains('(root)|'));
        self::assertSame(['[root]\\', '{root}|', 'admin'], $registry->allNicknames());
    }

    #[Test]
    public function everyConfiguredSpellingStaysARootOnAsciiNetworks(): void
    {
        $registry = new ConfiguredRootIdentityRegistry('Admin[, Admin{, admin[', NegotiatedCaseMapping::network(CaseMapping::Ascii));

        self::assertSame(['admin[', 'admin{'], $registry->allNicknames());
        self::assertTrue($registry->contains('ADMIN['));
        self::assertTrue($registry->contains('admin{'));
        self::assertFalse($registry->contains('Admin]'));
    }
}
