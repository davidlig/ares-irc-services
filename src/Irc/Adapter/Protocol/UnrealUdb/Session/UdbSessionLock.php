<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbFilePathStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Daemon-side holder of the UDB directory lock, via symfony/lock.
 *
 * `.udb.lock` is the single lock contract for the offline UDB directory:
 * the daemon acquires it for the whole UnrealUdb session so `udb:takeover`
 * cannot replace the dataset under a live link, and the takeover refuses to
 * run while the daemon holds it. An empty directory disables the lock
 * (nothing to protect) with a warning.
 */
final class UdbSessionLock
{
    private ?LockInterface $lock = null;

    private ?LockFactory $factory = null;

    public function __construct(
        private readonly string $directory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function acquire(): void
    {
        if ('' === $this->directory) {
            $this->logger->warning('IRC_UDB_DIRECTORY is not configured; the UDB session lock is not enforced.');

            return;
        }

        $lock = $this->factory()->createLock('udb-session', null);
        if (!$lock->acquire(false)) {
            throw new RuntimeException('The UDB directory is locked by another UDB process. Stop the daemon or the takeover before connecting.');
        }

        $this->lock = $lock;
        $this->logger->info('UDB session lock acquired.', ['directory' => $this->directory]);
    }

    public function release(): void
    {
        if (null === $this->lock) {
            return;
        }

        $this->lock->release();
        $this->lock = null;
        $this->logger->info('UDB session lock released.');
    }

    public function isHeld(): bool
    {
        return null !== $this->lock && $this->lock->isAcquired();
    }

    /** The exact-path store is shared by every acquire/release of this instance. */
    private function factory(): LockFactory
    {
        return $this->factory ??= new LockFactory(new UdbFilePathStore($this->directory . '/.udb.lock'));
    }
}
