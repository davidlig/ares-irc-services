<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ;

use App\Infrastructure\NickServ\PendingNickRestoreRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingNickRestoreRegistry::class)]
final class PendingNickRestoreRegistryTest extends TestCase
{
    private PendingNickRestoreRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PendingNickRestoreRegistry();
    }

    #[Test]
    public function markAddsUid(): void
    {
        $this->registry->mark('001ABCD');

        self::assertTrue($this->registry->peek('001ABCD'));
    }

    #[Test]
    public function peekReturnsFalseWhenNotMarked(): void
    {
        self::assertFalse($this->registry->peek('001ABCD'));
    }

    #[Test]
    public function peekDoesNotRemoveEntry(): void
    {
        $this->registry->mark('001ABCD');

        $this->registry->peek('001ABCD');
        self::assertTrue($this->registry->peek('001ABCD'));
    }

    #[Test]
    public function consumeReturnsTrueAndRemovesEntry(): void
    {
        $this->registry->mark('001ABCD');

        self::assertTrue($this->registry->consume('001ABCD'));
        self::assertFalse($this->registry->peek('001ABCD'));
    }

    #[Test]
    public function consumeReturnsFalseWhenNotMarked(): void
    {
        self::assertFalse($this->registry->consume('001ABCD'));
    }

    #[Test]
    public function consumeIsIdempotent(): void
    {
        $this->registry->mark('001ABCD');

        self::assertTrue($this->registry->consume('001ABCD'));
        self::assertFalse($this->registry->consume('001ABCD'));
        self::assertFalse($this->registry->consume('001ABCD'));
    }

    #[Test]
    public function multipleUidsTrackedIndependently(): void
    {
        $this->registry->mark('001AAAA');
        $this->registry->mark('001BBBB');
        $this->registry->mark('001CCCC');

        self::assertTrue($this->registry->peek('001AAAA'));
        self::assertTrue($this->registry->peek('001BBBB'));
        self::assertTrue($this->registry->peek('001CCCC'));

        $this->registry->consume('001BBBB');

        self::assertTrue($this->registry->peek('001AAAA'));
        self::assertFalse($this->registry->peek('001BBBB'));
        self::assertTrue($this->registry->peek('001CCCC'));
    }
}
