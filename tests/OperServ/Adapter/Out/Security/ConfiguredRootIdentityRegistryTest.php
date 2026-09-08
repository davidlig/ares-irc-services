<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Security;

use App\OperServ\Adapter\Out\Security\ConfiguredRootIdentityRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredRootIdentityRegistry::class)]
final class ConfiguredRootIdentityRegistryTest extends TestCase
{
    #[Test]
    public function parsesConfiguredIdentitiesCaseInsensitivelyAndIgnoresEmptyItems(): void
    {
        $registry = new ConfiguredRootIdentityRegistry(' Root, SECOND ,, root ');

        self::assertTrue($registry->contains('ROOT'));
        self::assertTrue($registry->contains('second'));
        self::assertFalse($registry->contains('other'));
        self::assertSame(['root', 'second'], $registry->allNicknames());
    }
}
