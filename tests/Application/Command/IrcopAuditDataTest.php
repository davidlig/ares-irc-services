<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Application\Command\IrcopAuditData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopAuditData::class)]
final class IrcopAuditDataTest extends TestCase
{
    #[Test]
    public function constructorWithAllParameters(): void
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

    #[Test]
    public function constructorWithTargetOnly(): void
    {
        $data = new IrcopAuditData(target: 'SomeUser');

        self::assertSame('SomeUser', $data->target);
        self::assertNull($data->targetHost);
        self::assertNull($data->targetIp);
        self::assertNull($data->reason);
        self::assertSame([], $data->extra);
    }

    #[Test]
    public function constructorWithTargetAndReason(): void
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
