<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;
use App\OperServ\Adapter\Out\Projection\DoctrineOperatorModeCatalog;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineOperatorModeCatalog::class)]
final class DoctrineOperatorModeCatalogTest extends TestCase
{
    #[Test]
    public function returnsNullWithoutAnActiveProtocolModule(): void
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);

        self::assertNull(new DoctrineOperatorModeCatalog($connection)->available());
    }

    #[Test]
    public function returnsTheActiveProtocolsIrcopModes(): void
    {
        $support = $this->createStub(UserModeSupportInterface::class);
        $support->method('getIrcOpUserModes')->willReturn(['H', 'W']);
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getUserModeSupport')->willReturn($support);
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn($module);

        self::assertSame(['H', 'W'], new DoctrineOperatorModeCatalog($connection)->available());
    }
}
