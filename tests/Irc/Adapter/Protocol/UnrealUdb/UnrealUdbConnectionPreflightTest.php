<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbConnectionPreflight;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UnrealUdbConnectionPreflight::class)]
final class UnrealUdbConnectionPreflightTest extends TestCase
{
    #[Test]
    public function approvedStoreNeedsNoPreparation(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(true);

        $result = new UnrealUdbConnectionPreflight($authority)->prepare();

        self::assertTrue($result->ready);
        self::assertNull($result->message);
    }

    #[Test]
    public function peerBootstrapAllowsUnapprovedStore(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);

        $result = new UnrealUdbConnectionPreflight($authority, true)->prepare();

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

        $result = new UnrealUdbConnectionPreflight($authority)->prepare();

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
        )->prepare();

        self::assertFalse($result->ready);
        self::assertSame('UDB fresh bootstrap failed: Database error.', $result->message);
    }
}
