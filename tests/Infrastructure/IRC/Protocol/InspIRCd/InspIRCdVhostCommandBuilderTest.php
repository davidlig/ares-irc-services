<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdVhostCommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspIRCdVhostCommandBuilder::class)]
final class InspIRCdVhostCommandBuilderTest extends TestCase
{
    private InspIRCdVhostCommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InspIRCdVhostCommandBuilder();
    }

    #[Test]
    public function getSetVhostLineProducesFhostCommand(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '001',
            targetUid: '001ABCD',
            vhost: 'new.vhost.example'
        );

        self::assertSame(':001ABCD FHOST new.vhost.example', $line);
    }

    #[Test]
    public function getSetVhostLineEscapesVhostWithSpaces(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '002',
            targetUid: '002EFGH',
            vhost: 'vhost with spaces'
        );

        self::assertSame(':002EFGH FHOST :vhost with spaces', $line);
    }

    #[Test]
    public function getClearVhostLineProducesFhostAsterisk(): void
    {
        $line = $this->builder->getClearVhostLine(
            serverSid: '001',
            targetUid: '001ABCD'
        );

        self::assertSame(':001ABCD FHOST *', $line);
    }

    #[Test]
    public function getSetVhostLineUsesTargetUidAsSource(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '003',
            targetUid: '003IJKL',
            vhost: 'test.vhost'
        );

        self::assertStringStartsWith(':003IJKL FHOST', $line);
        self::assertFalse(str_starts_with($line, ':003 '));
    }
}
