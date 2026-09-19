<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function fclose;
use function flock;
use function fopen;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

#[CoversClass(UdbSessionLock::class)]
final class UdbSessionLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ares-udb-lock-' . uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/.udb.lock');
        @rmdir($this->directory);
    }

    #[Test]
    public function anEmptyDirectoryDisablesTheLockWithAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $lock = new UdbSessionLock('', $logger);
        $lock->acquire();

        self::assertFalse($lock->isHeld());
    }

    #[Test]
    public function acquireHoldsTheLockForTheWholeSession(): void
    {
        $lock = new UdbSessionLock($this->directory);

        $lock->acquire();

        self::assertTrue($lock->isHeld());
        self::assertFileExists($this->directory . '/.udb.lock');
    }

    #[Test]
    public function theHeldLockExcludesTakeoverStyleFlock(): void
    {
        $lock = new UdbSessionLock($this->directory);
        $lock->acquire();

        $foreign = fopen($this->directory . '/.udb.lock', 'c');
        self::assertNotFalse($foreign);
        self::assertFalse(flock($foreign, LOCK_EX | LOCK_NB));
        fclose($foreign);
    }

    #[Test]
    public function releaseRestoresAvailability(): void
    {
        $lock = new UdbSessionLock($this->directory);
        $lock->acquire();

        $lock->release();

        self::assertFalse($lock->isHeld());

        $foreign = fopen($this->directory . '/.udb.lock', 'c');
        self::assertNotFalse($foreign);
        self::assertTrue(flock($foreign, LOCK_EX | LOCK_NB));
        flock($foreign, LOCK_UN);
        fclose($foreign);
    }

    #[Test]
    public function acquireFailsWhenAnotherProcessHoldsTheDirectory(): void
    {
        $foreign = fopen($this->directory . '/.udb.lock', 'c');
        self::assertNotFalse($foreign);
        self::assertTrue(flock($foreign, LOCK_EX | LOCK_NB));

        try {
            $lock = new UdbSessionLock($this->directory);

            $this->expectException(RuntimeException::class);
            $lock->acquire();
        } finally {
            flock($foreign, LOCK_UN);
            fclose($foreign);
        }
    }

    #[Test]
    public function releaseWithoutAcquisitionIsANoOp(): void
    {
        $lock = new UdbSessionLock('');

        $lock->release();

        self::assertFalse($lock->isHeld());
    }
}
