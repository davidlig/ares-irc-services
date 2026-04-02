<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Application\Command\IrcopAuditData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopAuditData::class)]
final class IrcopAuditDataTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        $data = new IrcopAuditData(
            target: 'BadUser',
            targetHost: 'user@host.com',
            targetIp: '10.0.0.1',
            reason: 'Flooding',
            extra: ['duration' => '1h'],
        );

        self::assertSame('BadUser', $data->target);
        self::assertSame('user@host.com', $data->targetHost);
        self::assertSame('10.0.0.1', $data->targetIp);
        self::assertSame('Flooding', $data->reason);
        self::assertSame(['duration' => '1h'], $data->extra);
    }

    public function testConstructorWithTargetOnly(): void
    {
        $data = new IrcopAuditData(target: 'SomeUser');

        self::assertSame('SomeUser', $data->target);
        self::assertNull($data->targetHost);
        self::assertNull($data->targetIp);
        self::assertNull($data->reason);
        self::assertSame([], $data->extra);
    }

    public function testConstructorWithTargetAndReason(): void
    {
        $data = new IrcopAuditData(
            target: 'Spammer',
            reason: 'Spamming channels',
        );

        self::assertSame('Spammer', $data->target);
        self::assertNull($data->targetHost);
        self::assertNull($data->targetIp);
        self::assertSame('Spamming channels', $data->reason);
        self::assertSame([], $data->extra);
    }
}
