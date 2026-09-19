<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceUidProviderInterface;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\NickServ\Adapter\Out\Security\OperNickProtectionExemption;
use App\OperServ\Application\Port\In\ProtectedNickQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperNickProtectionExemption::class)]
final class OperNickProtectionExemptionTest extends TestCase
{
    #[Test]
    public function delegatesOperChecksAndResolvesServiceNicknames(): void
    {
        $query = $this->createStub(ProtectedNickQuery::class);
        $query->method('isRootNickname')->willReturnMap([['RootAdmin', true], ['Other', false]]);
        $query->method('isIrcopNickId')->willReturnMap([[42, true], [99, false]]);
        $provider = new class implements ServiceUidProviderInterface, ServiceNicknameProviderInterface {
            public function getUid(): string
            {
                return '001AAAAAA';
            }

            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
        $exemption = new OperNickProtectionExemption($query, ServiceUidRegistry::fromIterable([$provider]));

        self::assertTrue($exemption->isRootNickname('RootAdmin'));
        self::assertFalse($exemption->isRootNickname('Other'));
        self::assertTrue($exemption->isServiceNickname('NickServ'));
        self::assertFalse($exemption->isServiceNickname('Other'));
        self::assertTrue($exemption->isIrcopNickId(42));
        self::assertFalse($exemption->isIrcopNickId(99));
    }
}
