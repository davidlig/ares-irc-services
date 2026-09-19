<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\ParsedUdbPath;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_slice;
use function count;
use function implode;
use function sprintf;

/**
 * Applies OperServ RAW UDB mutations (DB * INS / DB * DEL) to the
 * authoritative services store.
 *
 * Paths arrive exactly as the operator typed them on the wire: the block
 * letter followed by canonically percent-encoded components. Every step is
 * validated here so the writer never silently rejects an intercepted
 * mutation, and secret values (N::pass and S::encryption_key) are redacted
 * from the returned audit line.
 */
final readonly class UnrealUdbRawCommandHandler implements UdbRawCommandHandlerInterface
{
    public function __construct(
        private UdbRecordWriterInterface $recordWriter,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function ins(string $blockPath, string $value): UdbRawCommandResult
    {
        $parsed = $this->parse($blockPath);
        if (!$parsed instanceof ParsedUdbPath) {
            return $parsed;
        }

        if ('' === $value
            || !UdbSchema::validate($parsed->block, $parsed->components, $value)
            || !UdbPathCodec::fitsLimits($parsed->blockPath, $value)
        ) {
            return UdbRawCommandResult::error('raw.udb.invalid_value', ['%path%' => $this->redactedPath($parsed, $value)]);
        }

        if (!$this->recordWriter->insert($parsed->block->letter(), implode('::', $parsed->components), $value)) {
            return UdbRawCommandResult::error('raw.udb.error', ['%path%' => $this->redactedPath($parsed, $value)]);
        }

        $this->logger->info('UDB RAW INS applied by operator.', [
            'path' => $parsed->blockPath,
            'value' => UdbSchema::isSecret($parsed->block, $parsed->components) ? '<redacted>' : $value,
        ]);

        return UdbRawCommandResult::success($this->auditLine('INS', $parsed, $value));
    }

    public function del(string $blockPath): UdbRawCommandResult
    {
        $parsed = $this->parse($blockPath);
        if (!$parsed instanceof ParsedUdbPath) {
            return $parsed;
        }

        if (!$this->recordWriter->delete($parsed->block->letter(), implode('::', $parsed->components))) {
            return UdbRawCommandResult::error('raw.udb.error', ['%path%' => $this->redactedPath($parsed, null)]);
        }

        $this->logger->info('UDB RAW DEL applied by operator.', ['path' => $parsed->blockPath]);

        return UdbRawCommandResult::success($this->auditLine('DEL', $parsed, null));
    }

    /**
     * Strict parse of "N::encoded::path": uppercase block letter plus
     * canonical percent-encoded components. Returns the parsed path or an
     * error result.
     */
    private function parse(string $blockPath): ParsedUdbPath|UdbRawCommandResult
    {
        $parts = explode('::', $blockPath);

        $block = UdbBlock::tryFrom($parts[0]);
        if (null === $block) {
            return UdbRawCommandResult::error('raw.udb.invalid_block', ['%block%' => $parts[0]]);
        }

        if (!UdbPathCodec::isCanonicalPath($blockPath) || count($parts) < 2) {
            return UdbRawCommandResult::error('raw.udb.invalid_path', ['%path%' => $blockPath]);
        }

        // isCanonicalPath already proved every component decodes strictly.
        /** @var list<string> $components */
        $components = array_values(array_filter(
            array_map(UdbPathCodec::decodeComponent(...), array_slice($parts, 1)),
            static fn (?string $c): bool => null !== $c,
        ));

        return new ParsedUdbPath($block, $blockPath, $components);
    }

    private function redactedPath(ParsedUdbPath $parsed, ?string $value): string
    {
        return UdbSchema::isSecret($parsed->block, $parsed->components)
            ? $parsed->blockPath . ' <redacted>'
            : $parsed->blockPath . (null !== $value ? ' ' . $value : '');
    }

    private function auditLine(string $subcommand, ParsedUdbPath $parsed, ?string $value): string
    {
        $line = sprintf('DB * %s %s', $subcommand, $parsed->blockPath);

        if (null === $value) {
            return $line;
        }

        return $line . ' ' . (UdbSchema::isSecret($parsed->block, $parsed->components) ? '<redacted>' : $value);
    }
}
