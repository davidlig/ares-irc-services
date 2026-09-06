<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use RuntimeException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

use function fclose;
use function flock;
use function fopen;
use function is_resource;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Symfony Lock store backed by flock on ONE exact file path.
 *
 * FlockStore derives the lock filename from the key hash, so it cannot target
 * the shared `<directory>/.udb.lock` contract that the offline takeover also
 * holds. This store locks that exact path so daemon and takeover exclude each
 * other.
 */
final class UdbFilePathStore implements PersistingStoreInterface
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function save(Key $key): void
    {
        if ($key->hasState(self::class)) {
            return;
        }

        $handle = fopen($this->path, 'c');
        // @codeCoverageIgnoreStart
        // fopen can only fail through a concurrent removal (TOCTOU) of a path
        // the caller already validated; not reproducible deterministically.
        if (false === $handle) {
            throw new RuntimeException('Unable to open the UDB lock file.');
        }
        // @codeCoverageIgnoreEnd

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new LockConflictedException();
        }

        $key->setState(self::class, $handle);
        $key->markUnserializable();
    }

    public function delete(Key $key): void
    {
        if (!$key->hasState(self::class)) {
            return;
        }

        $handle = $key->getState(self::class);
        if (is_resource($handle)) {
            flock($handle, LOCK_UN | LOCK_NB);
            fclose($handle);
        }
        $key->removeState(self::class);
    }

    public function exists(Key $key): bool
    {
        return $key->hasState(self::class);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // flock holds the lock for as long as the process lives.
    }
}
