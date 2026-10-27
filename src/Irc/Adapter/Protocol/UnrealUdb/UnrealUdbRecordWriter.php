<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordMutationStoreInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

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
        $encodedPath = UdbPathCodec::encodePath($components);
        $typedValue = UdbSchema::typedValueFor($blockEnum, $components, $value);
        if (null === $typedValue || null === $encodedPath) {
            $this->logger->warning('Rejected UDB mutation failing schema validation.', [
                'block' => $blockEnum->letter(),
                'path' => $path,
            ]);

            return false;
        }

        $sequence = null;
        if (!$this->persistInsert($blockEnum->letter(), $encodedPath, $typedValue, $this->sessionState->streamOrigin(), $sequence)) {
            return false;
        }

        if (null !== $sequence) {
            $this->dispatch(new UdbMutation($blockEnum->letter(), $encodedPath, $typedValue)->assigned($sequence, $this->sessionState->streamOrigin()));
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

        $sequence = null;
        if (!$this->persistDelete($blockEnum->letter(), $encodedPath, $this->sessionState->streamOrigin(), $sequence)) {
            return false;
        }

        if (null !== $sequence) {
            $this->dispatch(new UdbMutation($blockEnum->letter(), $encodedPath, null)->assigned($sequence, $this->sessionState->streamOrigin()));
        }

        return true;
    }

    private function dispatch(UdbMutation $mutation): void
    {
        $this->sessionState->enqueueMutation($mutation);
    }

    private function persistInsert(string $block, string $encodedPath, UdbValue $value, UdbStreamOrigin $origin, ?UdbUnsignedDecimal &$sequence): bool
    {
        try {
            $sequence = $this->mutations->upsertWithManifest($block, $encodedPath, $value, $origin);

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

    private function persistDelete(string $block, string $encodedPath, UdbStreamOrigin $origin, ?UdbUnsignedDecimal &$sequence): bool
    {
        try {
            $sequence = $this->mutations->deleteCascadeWithManifest($block, $encodedPath, $origin);

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
