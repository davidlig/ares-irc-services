<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;

use function Amp\delay;
use function count;
use function hrtime;
use function implode;
use function max;
use function sprintf;

/**
 * Retries failed connects with exponential backoff, like Amp's RetrySocketConnector.
 *
 * Unlike Amp, every attempt and backoff fits inside one connect timeout, the backoff stops
 * as soon as the caller cancels, and errors keep the reasons of the failed attempts.
 */
final readonly class RetryingSocketConnector implements SocketConnector
{
    public function __construct(
        private SocketConnector $delegate,
        private int $maxAttempts = 3,
        private float $backoffBaseSeconds = 2.0,
        private float $minAttemptSeconds = 1.0,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('The maximum attempts must be at least 1.');
        }

        if ($backoffBaseSeconds <= 0) {
            throw new \InvalidArgumentException('The backoff base must be greater than 0 seconds.');
        }

        if ($minAttemptSeconds <= 0) {
            throw new \InvalidArgumentException('The minimum attempt time must be greater than 0 seconds.');
        }
    }

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $context ??= new ConnectContext();
        $deadline = self::now() + $context->getConnectTimeout();
        $failures = [];

        for ($attempt = 1;; ++$attempt) {
            // A backoff can overrun by a little, and Amp rejects a timeout that is not positive.
            $remaining = max($deadline - self::now(), 0.001);

            try {
                return $this->delegate->connect($uri, $context->withConnectTimeout($remaining), $cancellation);
            } catch (ConnectException $exception) {
                $failures[] = $exception->getMessage();
                if ($attempt === $this->maxAttempts) {
                    throw new ConnectException(sprintf('Connection to %s failed after %d attempts: %s', (string) $uri, $attempt, self::numbered($failures)), previous: $exception);
                }
            } catch (CancelledException $exception) {
                // A connector may carry the address failures of the cancelled attempt.
                if ($exception->getPrevious() instanceof ConnectException) {
                    $failures[] = $exception->getPrevious()->getMessage();
                }

                throw self::cancelled($uri, $failures, $exception);
            }

            // Retry only when the backoff still leaves the retry a useful amount of time.
            $backoff = $this->backoffBaseSeconds ** $attempt;
            if ($backoff + $this->minAttemptSeconds > $deadline - self::now()) {
                $count = count($failures);

                throw new ConnectException(sprintf('Connection to %s gave up after %d failed attempt%s; the next retry would not fit in the time left: %s', (string) $uri, $count, 1 === $count ? '' : 's', self::numbered($failures)), previous: $exception);
            }

            // Drop the exception and its trace before waiting.
            unset($exception);

            try {
                delay($backoff, cancellation: $cancellation);
            } catch (CancelledException $exception) {
                throw self::cancelled($uri, $failures, $exception);
            }
        }
    }

    /**
     * Keeps Amp's contract that a cancellation surfaces as CancelledException, with the
     * failed attempts in its previous exception.
     *
     * @param list<string> $failures
     */
    private static function cancelled(SocketAddress|string $uri, array $failures, CancelledException $exception): CancelledException
    {
        if ([] === $failures) {
            return $exception;
        }

        $count = count($failures);

        return new CancelledException(new ConnectException(sprintf('Connection to %s was cancelled after %d failed attempt%s: %s', (string) $uri, $count, 1 === $count ? '' : 's', self::numbered($failures)), previous: $exception));
    }

    /**
     * Attempt messages contain comma-separated address lists, so number each attempt.
     *
     * @param non-empty-list<string> $failures
     */
    private static function numbered(array $failures): string
    {
        $parts = [];
        foreach ($failures as $index => $failure) {
            $parts[] = sprintf('attempt %d: %s', $index + 1, $failure);
        }

        return implode('; ', $parts);
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
