<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\UdbMutation;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbPathCodec;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbSchema;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbWireCodec;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function assert;
use function explode;

/**
 * Writes UDB record mutations: validates against the full UDB 4 schema,
 * persists to the authoritative services store FIRST and only then
 * propagates to the wire.
 *
 * - Paths arrive as raw components ("davidlig::vhost") and are canonically
 *   percent-encoded here.
 * - A failed store write never reaches the wire (the store is the authority:
 *   the snapshot must always match what was announced).
 * - While the session coordinator is not authority-ready the wire mutation
 *   is queued in order and flushed on the next ready barrier.
 */
final readonly class UnrealUdbRecordWriter implements UdbRecordWriterInterface
{
    public function __construct(
        private ActiveConnectionHolderInterface $connectionHolder,
        private UdbSessionStateInterface $sessionState,
        private UdbRecordRepositoryInterface $records,
        private string $sid,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function insert(string $block, string $path, string $value): bool
    {
        $blockEnum = UdbBlock::tryFrom($block);
        if (null === $blockEnum || '' === $path) {
            $this->logger->warning('Rejected UDB mutation with invalid block or empty path.', [
                'block' => $block,
                'path' => $path,
            ]);

            return false;
        }

        $components = explode('::', $path);
        if (!UdbSchema::validate($blockEnum, $components, $value)) {
            $this->logger->warning('Rejected UDB mutation failing schema validation.', [
                'block' => $blockEnum->letter(),
                'path' => $path,
            ]);

            return false;
        }

        // The schema guarantees bounded, encodable components, so the
        // canonical encoding cannot fail here.
        $encodedPath = UdbPathCodec::encodePath($components);
        assert(null !== $encodedPath);

        if (!$this->persistInsert($blockEnum->letter(), $encodedPath, $value)) {
            return false;
        }

        $this->dispatch(new UdbMutation($blockEnum->letter(), $encodedPath, $value));

        return true;
    }

    public function delete(string $block, string $path): bool
    {
        $blockEnum = UdbBlock::tryFrom($block);
        if (null === $blockEnum || '' === $path) {
            $this->logger->warning('Rejected UDB deletion with invalid block or empty path.', [
                'block' => $block,
                'path' => $path,
            ]);

            return false;
        }

        $encodedPath = UdbPathCodec::encodePath(explode('::', $path));
        if (null === $encodedPath) {
            $this->logger->warning('Rejected UDB deletion with unencodable path.', [
                'block' => $blockEnum->letter(),
                'path' => $path,
            ]);

            return false;
        }

        if (!$this->persistDelete($blockEnum->letter(), $encodedPath)) {
            return false;
        }

        $this->dispatch(new UdbMutation($blockEnum->letter(), $encodedPath, null));

        return true;
    }

    /**
     * Sends the mutation when authority-ready; otherwise queues it for the
     * next ready flush (the store already holds the change).
     */
    private function dispatch(UdbMutation $mutation): void
    {
        if (!$this->sessionState->isAuthorityReady()) {
            $this->sessionState->enqueueMutation($mutation);

            return;
        }

        $this->send($mutation);
    }

    private function send(UdbMutation $mutation): void
    {
        if (!$this->connectionHolder->isConnected()) {
            $this->sessionState->enqueueMutation($mutation);

            return;
        }

        $line = null === $mutation->value
            ? UdbWireCodec::del($this->sid, $mutation->block, $mutation->encodedPath)
            : UdbWireCodec::ins($this->sid, $mutation->block, $mutation->encodedPath, $mutation->value);

        $this->connectionHolder->writeLine($line);
        $this->logger->debug('> ' . $line);
    }

    private function persistInsert(string $block, string $encodedPath, string $value): bool
    {
        try {
            $this->records->upsert($block, $encodedPath, $value);

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('UDB store insert failed; mutation not propagated.', [
                'block' => $block,
                'path' => $encodedPath,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function persistDelete(string $block, string $encodedPath): bool
    {
        try {
            $this->records->deleteCascade($block, $encodedPath);

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('UDB store delete failed; mutation not propagated.', [
                'block' => $block,
                'path' => $encodedPath,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
