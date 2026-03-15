<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use RuntimeException;

use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;

use const PHP_BINARY;
use const SIGTERM;

/**
 * Starts and stops the Messenger async consumer as a subprocess.
 * Used so the consumer runs only while the IRC link process is alive.
 */
final class ConsumerProcessManager implements ConsumerProcessManagerInterface
{
    private const int STOP_TIMEOUT_SECONDS = 10;

    /** @var resource|null */
    private $process;

    /** @var array{pipe: array<int, resource>}|null */
    private ?array $pipes = null;

    /**
     * @param list<string> $transportNames Transports to consume (e.g. ['async', 'async_emails'])
     */
    public function __construct(
        private readonly string $consolePath,
        private readonly array $transportNames = ['async', 'async_emails'],
    ) {
    }

    public function start(): void
    {
        if (null !== $this->process) {
            return;
        }

        $transports = implode(' ', $this->transportNames);
        $command = sprintf(
            '%s %s messenger:consume %s --no-reset',
            PHP_BINARY,
            $this->consolePath,
            $transports,
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
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
    }

    public function stop(): void
    {
        if (null === $this->process || !is_resource($this->process)) {
            $this->process = null;
            $this->pipes = null;

            return;
        }

        proc_terminate($this->process, SIGTERM);

        $end = time() + self::STOP_TIMEOUT_SECONDS;
        while (time() < $end) {
            $status = proc_get_status($this->process);
            if (false !== $status && !$status['running']) {
                break;
            }
            usleep(50_000);
        }

        if (is_resource($this->process)) {
            @proc_terminate($this->process, 9);
            proc_close($this->process);
        }

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

        $this->process = null;
        $this->pipes = null;
    }

    public function isRunning(): bool
    {
        if (null === $this->process || !is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return false !== $status && $status['running'];
    }
}
