<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Messenger;

use App\Irc\Adapter\In\Cli\ConsumerProcessManagerInterface;
use App\Irc\Adapter\Runtime\LoopSchedulerInterface;
use App\Irc\Adapter\Runtime\RevoltLoopScheduler;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;

use const PHP_BINARY;
use const SIGTERM;
use const STDERR;
use const STDOUT;

/**
 * Starts and stops the Messenger async consumer as a subprocess.
 * Used so the consumer runs only while the IRC link process is alive.
 */
final class ConsumerProcessManager implements ConsumerProcessManagerInterface
{
    private const int STOP_TIMEOUT_SECONDS = 10;

    private const int HEALTHY_RUNTIME_SECONDS = 60;

    private const int MAX_RESTART_DELAY_SECONDS = 30;

    /** @var resource|null Process handle from proc_open */
    private mixed $process = null;

    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    private ?string $supervisorId = null;

    /** @var Closure(): float */
    private readonly Closure $now;

    private ?float $startedAt = null;

    private ?float $nextRestartAt = null;

    private int $restartDelaySeconds = 1;

    /**
     * @param list<string>            $transportNames Transports to consume (e.g. ['async', 'async_emails'])
     * @param (Closure(): float)|null $now
     */
    public function __construct(
        private readonly string $consolePath,
        private readonly array $transportNames = ['async', 'async_emails'],
        private readonly LoopSchedulerInterface $scheduler = new RevoltLoopScheduler(),
        ?Closure $now = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function start(): void
    {
        if (null !== $this->process || null !== $this->supervisorId) {
            return;
        }

        $this->spawn();
        $this->supervisorId = $this->scheduler->repeat(1.0, function (): void {
            $now = ($this->now)();
            $status = null !== $this->process ? proc_get_status($this->process) : null;
            if (null !== $status && $status['running']) {
                if (null !== $this->startedAt && $now - $this->startedAt >= self::HEALTHY_RUNTIME_SECONDS) {
                    $this->restartDelaySeconds = 1;
                }

                return;
            }

            if (null !== $this->process) {
                $healthy = null !== $status && 0 === $status['exitcode'];
                proc_close($this->process);
                $this->process = null;
                $this->closePipes();
                $this->nextRestartAt = $now + ($healthy ? 0 : $this->restartDelaySeconds);
                $this->logger->log($healthy ? 'info' : 'warning', 'Messenger consumer exited; scheduling restart.', [
                    'exit_code' => $status['exitcode'] ?? null,
                    'restart_delay_seconds' => $healthy ? 0 : $this->restartDelaySeconds,
                ]);
                $this->restartDelaySeconds = $healthy ? 1 : min(self::MAX_RESTART_DELAY_SECONDS, $this->restartDelaySeconds * 2);
            }

            if (null !== $this->nextRestartAt && $now >= $this->nextRestartAt) {
                $this->spawn();
                $this->nextRestartAt = null;
            }
        });
    }

    private function spawn(): void
    {
        $command = [PHP_BINARY, $this->consolePath, 'messenger:consume', ...$this->transportNames, '--time-limit=3600', '--memory-limit=384M'];

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ];

        $proc = @proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            null,
            null,
        );

        // @codeCoverageIgnoreStart
        // Cannot test proc_open failure in unit tests - requires OS-level failure.
        if (false === $proc || !is_resource($proc)) {
            throw new RuntimeException('Failed to start Messenger consumer process.');
        }
        // @codeCoverageIgnoreEnd

        $this->process = $proc;
        $this->pipes = $pipes;
        $this->startedAt = ($this->now)();
    }

    public function stop(): void
    {
        if (null !== $this->supervisorId) {
            $this->scheduler->cancel($this->supervisorId);
            $this->supervisorId = null;
        }

        $this->nextRestartAt = null;
        $this->startedAt = null;
        $this->restartDelaySeconds = 1;

        if (null === $this->process || !is_resource($this->process)) {
            $this->process = null;
            $this->closePipes();

            return;
        }

        proc_terminate($this->process, SIGTERM);

        $end = time() + self::STOP_TIMEOUT_SECONDS;
        while (time() < $end) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            usleep(50_000);
        }

        if (proc_get_status($this->process)['running']) {
            @proc_terminate($this->process, 9);
        }
        proc_close($this->process);

        $this->process = null;
        $this->closePipes();
    }

    private function closePipes(): void
    {
        if (null !== $this->pipes) {
            foreach ($this->pipes as $pipe) {
                // @codeCoverageIgnoreStart
                // Cannot test fclose on invalid resource in unit tests.
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
                // @codeCoverageIgnoreEnd
            }
        }

        $this->pipes = null;
    }

    public function isRunning(): bool
    {
        if (null === $this->process || !is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }
}
