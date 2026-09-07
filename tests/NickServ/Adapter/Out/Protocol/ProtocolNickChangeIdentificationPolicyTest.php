<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Protocol;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\NickServ\Adapter\Out\Protocol\ProtocolNickChangeIdentificationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolNickChangeIdentificationPolicy::class)]
final class ProtocolNickChangeIdentificationPolicyTest extends TestCase
{
    #[Test]
    public function reportsWhetherTheActiveProtocolPreservesIdentification(): void
    {
        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('getProtocolModule')->willReturnOnConsecutiveCalls(
            $this->createStub(PreservingProtocol::class),
            $this->createStub(ProtocolModuleInterface::class),
        );
        $policy = new ProtocolNickChangeIdentificationPolicy($holder);

        self::assertTrue($policy->preservesIdentification());
        self::assertFalse($policy->preservesIdentification());
    }
}

interface PreservingProtocol extends ProtocolModuleInterface, NickChangePreservesIdentificationInterface {}
