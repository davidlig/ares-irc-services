<?php

declare(strict_types=1);

namespace App\Tests\Application\ApplicationPort;

use App\Application\ApplicationPort\ServiceUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceUidGenerator::class)]
final class ServiceUidGeneratorTest extends TestCase
{
    #[Test]
    public function generateUidReturnsCorrectFormat(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('0A0');

        $generator = new ServiceUidGenerator($connectionHolder);

        self::assertSame('0A0A00001', $generator->generateUid('nickserv'));
    }

    #[Test]
    public function generateUidUsesCorrectLettersPerService(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new ServiceUidGenerator($connectionHolder);

        self::assertSame('001A00001', $generator->generateUid('nickserv'));
    }

    #[Test]
    public function generateUidCachesByServiceKey(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new ServiceUidGenerator($connectionHolder);

        $first = $generator->generateUid('nickserv');
        $second = $generator->generateUid('nickserv');

        self::assertSame($first, $second);
    }

    #[Test]
    public function generateUidIncrementsCounter(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new ServiceUidGenerator($connectionHolder);

        $first = $generator->generateUid('nickserv');
        $second = $generator->generateUid('chanserv');
        $third = $generator->generateUid('memoserv');
        $fourth = $generator->generateUid('operserv');

        self::assertSame('001A00001', $first);
        self::assertSame('001B00002', $second);
        self::assertSame('001C00003', $third);
        self::assertSame('001E00004', $fourth);
    }

    #[Test]
    public function generateUidUsesDefaultLetterForUnknownService(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new ServiceUidGenerator($connectionHolder);

        self::assertSame('001Z00001', $generator->generateUid('unknown'));
    }

    #[Test]
    public function generateUidUsesDefaultSidWhenNull(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn(null);

        $generator = new ServiceUidGenerator($connectionHolder);

        self::assertSame('000A00001', $generator->generateUid('nickserv'));
    }
}
