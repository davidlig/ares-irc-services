<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Security;

use App\Application\NickServ\Security\NickServPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServPermission::class)]
final class NickServPermissionTest extends TestCase
{
    #[Test]
    public function identifiedOwnerConstant(): void
    {
        self::assertSame('nickserv_identified_owner', NickServPermission::IDENTIFIED_OWNER);
    }

    #[Test]
    public function networkOperConstant(): void
    {
        self::assertSame('network_oper', NickServPermission::NETWORK_OPER);
    }
}
