<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function chmod;
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
        $this->directory = sys_get_temp_dir() . '/ares-udb-lock-' . uniqid('', true) . '/var/lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/udb.lock');
        @rmdir($this->directory);
        @rmdir(dirname($this->directory));
        @rmdir(dirname(dirname($this->directory)));
    }

    #[Test]
    public function acquireCreatesTheApplicationLockDirectoryWhenMissing(): void
    {
        $lock = new UdbSessionLock($this->directory);
        $lock->acquire();

        self::assertTrue($lock->isHeld());
        self::assertFileExists($this->directory . '/udb.lock');
    }

    #[Test]
    public function acquireHoldsTheLockForTheWholeSession(): void
    {
        $lock = new UdbSessionLock($this->directory);

        $lock->acquire();

        self::assertTrue($lock->isHeld());
        self::assertFileExists($this->directory . '/udb.lock');
    }

    #[Test]
    public function theHeldLockExcludesOtherLocalFlockUsers(): void
    {
        $lock = new UdbSessionLock($this->directory);
        $lock->acquire();

        $foreign = fopen($this->directory . '/udb.lock', 'c');
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

        $foreign = fopen($this->directory . '/udb.lock', 'c');
        self::assertNotFalse($foreign);
        self::assertTrue(flock($foreign, LOCK_EX | LOCK_NB));
        flock($foreign, LOCK_UN);
        fclose($foreign);
    }

    #[Test]
    public function acquireFailsWhenAnotherProcessHoldsTheDirectory(): void
    {
        mkdir($this->directory, 0o770, true);
        $foreign = fopen($this->directory . '/udb.lock', 'c');
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

    #[Test]
    public function anExistingFileCannotBeUsedAsTheLockDirectory(): void
    {
        $parent = dirname(dirname($this->directory));
        mkdir($parent);
        $file = $parent . '/not-a-directory';
        file_put_contents($file, '');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('UDB lock directory is not a directory.');
            new UdbSessionLock($file)->acquire();
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function cannotCreateADirectoryUnderAnExistingFile(): void
    {
        $parent = dirname(dirname($this->directory));
        mkdir($parent);
        $file = $parent . '/file';
        file_put_contents($file, '');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to create UDB lock directory');
            new UdbSessionLock($file . '/child')->acquire();
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function lockFileFilesystemErrorsFailExplicitlyWithoutHoldingTheLock(): void
    {
        mkdir($this->directory . '/udb.lock', 0o770, true);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to acquire the local UDB session lock');
            new UdbSessionLock($this->directory)->acquire();
        } finally {
            rmdir($this->directory . '/udb.lock');
        }
    }

    #[Test]
    public function aMissingLockDirectoryConfigurationFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UDB lock directory is not configured.');
        new UdbSessionLock('')->acquire();
    }

    #[Test]
    public function aDirectoryWithoutWritePermissionFailsExplicitly(): void
    {
        mkdir($this->directory, 0o770, true);
        chmod($this->directory, 0o555);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is not writable.');
            new UdbSessionLock($this->directory)->acquire();
        } finally {
            chmod($this->directory, 0o770);
        }
    }
}
