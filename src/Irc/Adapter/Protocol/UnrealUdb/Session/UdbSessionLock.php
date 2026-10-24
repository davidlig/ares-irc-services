<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbFilePathStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

use function is_dir;
use function is_file;
use function is_writable;
use function mkdir;
use function rtrim;
use function sprintf;

/**
 * Daemon-side holder of the local UnrealUdb session lock, via symfony/lock.
 *
 * The application-owned lock directory contains one local `udb.lock` file.
 * This protects this process's filesystem state from concurrent local use; it
 * is not a distributed lock.
 */
final class UdbSessionLock
{
    private ?LockInterface $lock = null;

    private ?LockFactory $factory = null;

    public function __construct(
        private readonly string $lockDirectory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function acquire(): void
    {
        if ($this->isHeld()) {
            return;
        }

        $this->ensureLockDirectory();

        $lock = $this->factory()->createLock('udb-session', null);
        try {
            if (!$lock->acquire(false)) {
                throw new RuntimeException('The local UDB session lock is held by another process.');
            }
        } catch (LockAcquiringException $exception) {
            throw new RuntimeException(sprintf('Unable to acquire the local UDB session lock: %s', $exception->getPrevious()?->getMessage() ?? $exception->getMessage()), 0, $exception);
        }

        $this->lock = $lock;
        $this->logger->info('Local UDB session lock acquired.', ['directory' => $this->lockDirectory]);
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

    private function ensureLockDirectory(): void
    {
        if ('' === $this->lockDirectory) {
            throw new RuntimeException('UDB lock directory is not configured.');
        }

        $directory = $this->lockDirectory;

        if (!is_dir($directory)) {
            if (is_file($directory)) {
                throw new RuntimeException('UDB lock directory is not a directory.');
            }

            if (!@mkdir($directory, 0o770, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create UDB lock directory "%s".', $directory));
            }
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('UDB lock directory "%s" is not writable.', $directory));
        }
    }

    /** The exact-path store is shared by every acquire/release of this instance. */
    private function factory(): LockFactory
    {
        $directory = rtrim($this->lockDirectory, '/');

        return $this->factory ??= new LockFactory(new UdbFilePathStore($directory . '/udb.lock'));
    }
}
