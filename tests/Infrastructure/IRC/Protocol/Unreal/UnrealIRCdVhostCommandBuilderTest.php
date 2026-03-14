<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdVhostCommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealIRCdVhostCommandBuilder::class)]
final class UnrealIRCdVhostCommandBuilderTest extends TestCase
{
    private UnrealIRCdVhostCommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new UnrealIRCdVhostCommandBuilder();
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
    public function getClearVhostLineProducesSvs2modeMinusT(): void
    {
        $line = $this->builder->getClearVhostLine(
            serverSid: '001',
            targetUid: '001ABCD'
        );

        self::assertSame(':001 SVS2MODE 001ABCD -t', $line);
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
