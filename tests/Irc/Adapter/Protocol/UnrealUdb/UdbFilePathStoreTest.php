<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbFilePathStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;

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

#[CoversClass(UdbFilePathStore::class)]
final class UdbFilePathStoreTest extends TestCase
{
    private string $directory;

    private string $path;

    private UdbFilePathStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ares-udb-store-' . uniqid('', true);
        mkdir($this->directory);
        $this->path = $this->directory . '/.udb.lock';
        $this->store = new UdbFilePathStore($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir($this->directory);
    }

    #[Test]
    public function saveCreatesTheExactFileAndAcquiresExclusively(): void
    {
        $key = new Key('udb-session');

        $this->store->save($key);

        self::assertTrue($this->store->exists($key));
        self::assertFileExists($this->path);
    }

    #[Test]
    public function aSecondKeyConflictsWhileTheFirstHoldsTheLock(): void
    {
        $first = new Key('first');
        $this->store->save($first);

        $this->expectException(LockConflictedException::class);
        $this->store->save(new Key('second'));
    }

    #[Test]
    public function savingTheSameKeyAgainIsIdempotent(): void
    {
        $key = new Key('udb-session');

        $this->store->save($key);
        $this->store->save($key);

        self::assertTrue($this->store->exists($key));
    }

    #[Test]
    public function deleteReleasesTheLockForOtherKeys(): void
    {
        $key = new Key('udb-session');
        $this->store->save($key);

        $this->store->delete($key);

        self::assertFalse($this->store->exists($key));
        $this->store->save(new Key('replacement'));
    }

    #[Test]
    public function deleteWithoutOwnershipIsANoOp(): void
    {
        $this->store->delete(new Key('never-held'));

        self::assertFalse($this->store->exists(new Key('never-held')));
    }

    #[Test]
    public function putOffExpirationDoesNothing(): void
    {
        $key = new Key('udb-session');
        $this->store->save($key);

        $this->store->putOffExpiration($key, 30.0);

        self::assertTrue($this->store->exists($key));
    }

    #[Test]
    public function theLockExcludesPlainFlockHolders(): void
    {
        $key = new Key('udb-session');
        $this->store->save($key);

        $foreign = fopen($this->path, 'c');
        self::assertNotFalse($foreign);
        self::assertFalse(flock($foreign, LOCK_EX | LOCK_NB));
        fclose($foreign);
    }
}
