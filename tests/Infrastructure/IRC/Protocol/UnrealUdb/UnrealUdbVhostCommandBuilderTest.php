<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbVhostCommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbVhostCommandBuilder::class)]
final class UnrealUdbVhostCommandBuilderTest extends TestCase
{
    private UnrealUdbVhostCommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new UnrealUdbVhostCommandBuilder();
    }

    #[Test]
    public function getSetVhostLineProducesChghostCommand(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '001',
            targetUid: '001ABCD',
            vhost: 'new.vhost.example'
        );

        self::assertSame(':001 CHGHOST 001ABCD new.vhost.example', $line);
    }

    #[Test]
    public function getSetVhostLineEscapesVhostWithSpaces(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '002',
            targetUid: '002EFGH',
            vhost: 'vhost with spaces'
        );

        self::assertSame(':002 CHGHOST 002EFGH :vhost with spaces', $line);
    }

    #[Test]
    public function getClearVhostLinesProducesSvs2modeMinusT(): void
    {
        $lines = $this->builder->getClearVhostLines(
            serverSid: '001',
            targetUid: '001ABCD',
            realHost: 'real.host.example'
        );

        self::assertCount(1, $lines);
        self::assertSame(':001 SVS2MODE 001ABCD -t', $lines[0]);
    }

    #[Test]
    public function getSetVhostLineWithSimpleVhost(): void
    {
        $line = $this->builder->getSetVhostLine(
            serverSid: '003',
            targetUid: '003IJKL',
            vhost: 'simple.vhost'
        );

        self::assertSame(':003 CHGHOST 003IJKL simple.vhost', $line);
        self::assertStringEndsNotWith(':simple.vhost', $line);
        self::assertDoesNotMatchRegularExpression('/:003 CHGHOST 003IJKL :/', $line);
    }
}
