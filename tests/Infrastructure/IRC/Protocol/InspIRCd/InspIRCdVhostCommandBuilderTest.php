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
    public function getSetVhostLineProducesEncapChgHost(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '0A0',
            targetUid: '994AAAAAA',
            vhost: 'new.vhost.example'
        );

        self::assertSame(':0A0 ENCAP 994 CHGHOST 994AAAAAA new.vhost.example', $line);
    }

    #[Test]
    public function getSetVhostLineExtractsTargetSidFromUid(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '0A0',
            targetUid: '0A0BBBBBB',
            vhost: 'test.vhost'
        );

        self::assertSame(':0A0 ENCAP 0A0 CHGHOST 0A0BBBBBB test.vhost', $line);
    }

    #[Test]
    public function getClearVhostLinesProducesEncapChgHostAndModePlusX(): void
    {
        $lines = $this->builder->getClearVhostLines(
            serverSid: '0A0',
            targetUid: '994AAAAAA',
            realHost: '87.125.55.53'
        );

        self::assertCount(2, $lines);
        self::assertSame(':0A0 ENCAP 994 CHGHOST 994AAAAAA 87.125.55.53', $lines[0]);
        self::assertSame(':0A0 MODE 994AAAAAA +x', $lines[1]);
    }

    #[Test]
    public function getSetVhostLineUsesServerSidAsSource(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '0A0',
            targetUid: '994AAAAAA',
            vhost: 'test.vhost'
        );

        self::assertStringStartsWith(':0A0 ENCAP', $line);
    }
}
