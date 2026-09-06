<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\UdbOfflineTakeoverInterface;
use App\Domain\Udb\Repository\UdbAuthorityStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbConnectionPreflight;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UnrealUdbConnectionPreflight::class)]
final class UnrealUdbConnectionPreflightTest extends TestCase
{
    #[Test]
    public function ignoresOtherProtocols(): void
    {
        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->expects(self::never())->method('isApproved');
        $takeover = $this->createMock(UdbOfflineTakeoverInterface::class);
        $takeover->expects(self::never())->method('takeover');

        $result = new UnrealUdbConnectionPreflight($authority, $takeover, '')->prepare('unreal');

        self::assertTrue($result->ready);
        self::assertNull($result->message);
    }

    #[Test]
    public function approvedStoreNeedsNoPreparation(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(true);
        $takeover = $this->createMock(UdbOfflineTakeoverInterface::class);
        $takeover->expects(self::never())->method('takeover');

        $result = new UnrealUdbConnectionPreflight($authority, $takeover, '/udb')->prepare('unrealudb');

        self::assertTrue($result->ready);
        self::assertNull($result->message);
    }

    #[Test]
    public function peerBootstrapAllowsUnapprovedStore(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $takeover = $this->createMock(UdbOfflineTakeoverInterface::class);
        $takeover->expects(self::never())->method('takeover');

        $result = new UnrealUdbConnectionPreflight($authority, $takeover, '', true)->prepare('unrealudb');

        self::assertTrue($result->ready);
        self::assertNotNull($result->message);
        self::assertStringContainsString('bootstrap from peer enabled', $result->message);
    }

    #[Test]
    public function freshStoreIsApprovedWithoutOfflineTakeover(): void
    {
        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $authority->expects(self::once())->method('approve')
            ->with('43566f5c8bf11593153f78572a667c7ba7705b6b33bf0c3159273fc3661f73b3');
        $takeover = $this->createMock(UdbOfflineTakeoverInterface::class);
        $takeover->expects(self::never())->method('takeover');

        $result = new UnrealUdbConnectionPreflight($authority, $takeover, '')->prepare('unrealudb');

        self::assertTrue($result->ready);
        self::assertNotNull($result->message);
        self::assertStringContainsString('fresh bootstrap approved', $result->message);
    }

    #[Test]
    public function freshStoreApprovalFailureRejectsConnection(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $authority->method('approve')->willThrowException(new RuntimeException('Database error.'));

        $result = new UnrealUdbConnectionPreflight(
            $authority,
            $this->createStub(UdbOfflineTakeoverInterface::class),
            '',
        )->prepare('unrealudb');

        self::assertFalse($result->ready);
        self::assertSame('UDB fresh bootstrap failed: Database error.', $result->message);
    }

    #[Test]
    public function offlineStoreIsTakenOver(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $takeover = $this->createMock(UdbOfflineTakeoverInterface::class);
        $takeover->expects(self::once())->method('takeover')->with('/udb')->willReturn(str_repeat('a', 64));

        $result = new UnrealUdbConnectionPreflight($authority, $takeover, '/udb')->prepare('unrealudb');

        self::assertTrue($result->ready);
        self::assertNotNull($result->message);
        self::assertStringContainsString(str_repeat('a', 64), $result->message);
    }

    #[Test]
    public function offlineTakeoverFailureRejectsConnection(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $takeover = $this->createStub(UdbOfflineTakeoverInterface::class);
        $takeover->method('takeover')->willThrowException(new RuntimeException('Invalid snapshot.'));

        $result = new UnrealUdbConnectionPreflight($authority, $takeover, '/udb')->prepare('unrealudb');

        self::assertFalse($result->ready);
        self::assertSame('Automatic udb:takeover failed: Invalid snapshot.', $result->message);
    }
}
