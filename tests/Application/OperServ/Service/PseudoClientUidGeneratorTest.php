<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Service;

use App\Application\OperServ\Service\PseudoClientUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(PseudoClientUidGenerator::class)]
final class PseudoClientUidGeneratorTest extends TestCase
{
    #[Test]
    public function generateReturnsNullWhenNoServerSid(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn(null);

        $generator = new PseudoClientUidGenerator($connectionHolder);

        self::assertNull($generator->generate());
    }

    #[Test]
    public function generateReturnsUidWithServerSid(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new PseudoClientUidGenerator($connectionHolder);

        $uid = $generator->generate();

        self::assertNotNull($uid);
        self::assertStringStartsWith('001Z', $uid);
        self::assertSame(9, strlen($uid));
    }

    #[Test]
    public function generateReturnsUniqueUids(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new PseudoClientUidGenerator($connectionHolder);

        $uid1 = $generator->generate();
        $uid2 = $generator->generate();

        self::assertNotSame($uid1, $uid2);
    }

    #[Test]
    public function generateReturnsSequentialUids(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new PseudoClientUidGenerator($connectionHolder);

        $uid1 = $generator->generate();
        $uid2 = $generator->generate();
        $uid3 = $generator->generate();

        self::assertSame('001Z00001', $uid1);
        self::assertSame('001Z00002', $uid2);
        self::assertSame('001Z00003', $uid3);
    }

    #[Test]
    public function generateUsesCorrectServerSid(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('002');

        $generator = new PseudoClientUidGenerator($connectionHolder);

        $uid = $generator->generate();

        self::assertStringStartsWith('002Z', $uid);
    }

    #[Test]
    public function generateUsesBase36ForCounter(): void
    {
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $generator = new PseudoClientUidGenerator($connectionHolder);

        for ($i = 0; $i < 34; ++$i) {
            $generator->generate();
        }

        $uid = $generator->generate();

        self::assertSame('001Z0000Z', $uid);

        $uid = $generator->generate();

        self::assertSame('001Z00010', $uid);
    }
}
