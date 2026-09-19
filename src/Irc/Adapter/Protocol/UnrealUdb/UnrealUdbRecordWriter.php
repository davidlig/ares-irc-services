<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordMutationStoreInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
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
 * - Every changed mutation is handed to the session coordinator. It owns the
 *   single epoch-scoped sequence across all six blocks and either publishes
 *   it immediately or retains it until the next ready barrier.
 */
final readonly class UnrealUdbRecordWriter implements UdbRecordWriterInterface
{
    private UdbSessionStateInterface $sessionState;

    private UdbRecordMutationStoreInterface $mutations;

    private LoggerInterface $logger;

    public function __construct(
        UdbSessionStateInterface $sessionState,
        UdbRecordMutationStoreInterface $mutations,
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->sessionState = $sessionState;
        $this->mutations = $mutations;
        $this->logger = $logger;
    }

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
        $value = UdbSchema::canonicalizeValue($value);

        $changed = $this->persistInsert($blockEnum->letter(), $encodedPath, $value);
        if (null === $changed) {
            return false;
        }

        if ($changed) {
            $this->dispatch(new UdbMutation($blockEnum->letter(), $encodedPath, $value));
        }

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

        $changed = $this->persistDelete($blockEnum->letter(), $encodedPath);
        if (null === $changed) {
            return false;
        }

        if ($changed) {
            $this->dispatch(new UdbMutation($blockEnum->letter(), $encodedPath, null));
        }

        return true;
    }

    private function dispatch(UdbMutation $mutation): void
    {
        $this->sessionState->enqueueMutation($mutation);
    }

    /** @return ?bool null when persistence failed, otherwise whether it changed */
    private function persistInsert(string $block, string $encodedPath, string $value): ?bool
    {
        try {
            return $this->mutations->upsertWithManifest($block, $encodedPath, $value);
        } catch (Throwable $exception) {
            $this->logger->error('UDB store insert failed; mutation not propagated.', [
                'block' => $block,
                'path' => $encodedPath,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** @return ?bool null when persistence failed, otherwise whether it changed */
    private function persistDelete(string $block, string $encodedPath): ?bool
    {
        try {
            return $this->mutations->deleteCascadeWithManifest($block, $encodedPath);
        } catch (Throwable $exception) {
            $this->logger->error('UDB store delete failed; mutation not propagated.', [
                'block' => $block,
                'path' => $encodedPath,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
