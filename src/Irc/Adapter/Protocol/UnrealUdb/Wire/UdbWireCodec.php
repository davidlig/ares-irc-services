<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;

use function array_slice;
use function array_values;
use function assert;
use function count;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strpbrk;
use function strpos;
use function strtoupper;
use function substr;

/** Exact codec for the UDB 4 wire contract at upstream revision ac3b915. */
final class UdbWireCodec
{
    /** @param list<string> $capabilities */
    public static function hel(string $sid, string $targetSid, string $propagator, string $epoch, array $capabilities = ['OCL']): string
    {
        return sprintf(':%s DB %s HEL 4 %s %s %s', $sid, $targetSid, $propagator, $epoch, implode(' ', $capabilities));
    }

    /** @param list<string> $capabilities */
    public static function helAck(string $sid, string $targetSid, string $propagator, string $epoch, array $capabilities = ['OCL']): string
    {
        return sprintf(':%s DB %s HEL 4 ACK %s %s %s', $sid, $targetSid, $propagator, $epoch, implode(' ', $capabilities));
    }

    public static function inf(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $digest, int $recordCount, int $modifiedAt, int|UdbUnsignedDecimal|null $watermark = null): string
    {
        return sprintf(':%s DB %s INF %s %s %s %d %d%s', $sid, $targetSid, $roundId, $block->letter(), self::digestOrEmpty($digest), $recordCount, $modifiedAt, self::optionalDecimal($watermark));
    }

    public static function res(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block): string
    {
        return sprintf(':%s DB %s RES %s %s', $sid, $targetSid, $roundId, $block->letter());
    }

    public static function begin(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $digest, int|UdbUnsignedDecimal|null $watermark = null): string
    {
        return self::stagedBoundary('BEGIN', $sid, $targetSid, $roundId, $block, $txid, $digest, $watermark);
    }

    public static function put(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $encodedPath, string $value): string
    {
        return sprintf(':%s DB %s PUT %s %s %s %s :%s', $sid, $targetSid, $roundId, $block->letter(), $txid, $encodedPath, $value);
    }

    public static function end(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $digest, int|UdbUnsignedDecimal|null $watermark = null): string
    {
        return self::stagedBoundary('END', $sid, $targetSid, $roundId, $block, $txid, $digest, $watermark);
    }

    public static function ack(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $digest, int|UdbUnsignedDecimal|null $watermark = null): string
    {
        return self::stagedBoundary('ACK', $sid, $targetSid, $roundId, $block, $txid, $digest, $watermark);
    }

    public static function err(string $sid, string $targetSid, string $subcommand, int $errorCode, int|UdbUnsignedDecimal $roundId, ?UdbBlock $block): string
    {
        return sprintf(':%s DB %s ERR %s %d %s %s', $sid, $targetSid, $subcommand, $errorCode, $roundId, null !== $block ? $block->letter() : '0');
    }

    public static function ins(string $sid, string $epoch, int|UdbUnsignedDecimal $sequence, string $blockLetter, string $encodedPath, string $value): string
    {
        return sprintf(':%s DB * INS %s %s %s::%s :%s', $sid, $epoch, $sequence, $blockLetter, $encodedPath, $value);
    }

    public static function del(string $sid, string $epoch, int|UdbUnsignedDecimal $sequence, string $blockLetter, string $encodedPath): string
    {
        return sprintf(':%s DB * DEL %s %s %s::%s', $sid, $epoch, $sequence, $blockLetter, $encodedPath);
    }

    public static function drp(string $sid, string $epoch, int|UdbUnsignedDecimal $sequence, UdbBlock $block): string
    {
        return sprintf(':%s DB * DRP %s %s %s', $sid, $epoch, $sequence, $block->letter());
    }

    public static function exp(string $sid, string $targetSid, string $encodedPath, int $expectedExpires): string
    {
        return sprintf(':%s DB %s EXP %s %d', $sid, $targetSid, $encodedPath, $expectedExpires);
    }

    public static function manifestReq(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId): string
    {
        return sprintf(':%s DB %s MANIFEST REQ %s', $sid, $targetSid, $roundId);
    }

    public static function manifestAck(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, int $recordCount, string $digest, int|UdbUnsignedDecimal $watermark): string
    {
        return sprintf(':%s DB %s MANIFEST ACK %s %s %d %s %s', $sid, $targetSid, $roundId, $block->letter(), $recordCount, self::digestOrEmpty($digest), $watermark);
    }

    /** Returns null for any frame that does not match the current grammar exactly. */
    public static function parse(IRCMessage $message): ?UdbFrame
    {
        if ('DB' !== strtoupper($message->command) || null === $message->prefix || count($message->params) < 2) {
            return null;
        }
        $sourceSid = $message->prefix;
        $target = $message->params[0];

        return match (strtoupper($message->params[1])) {
            'HEL' => self::parseHel($sourceSid, $target, $message),
            'INF' => self::parseInf($sourceSid, $target, $message),
            'RES' => self::parseRes($sourceSid, $target, $message),
            'BEGIN' => self::parseBoundary(UdbFrameKind::Begin, $sourceSid, $target, $message),
            'PUT' => self::parsePut($sourceSid, $target, $message),
            'END' => self::parseBoundary(UdbFrameKind::End, $sourceSid, $target, $message),
            'ACK' => self::parseBoundary(UdbFrameKind::Ack, $sourceSid, $target, $message),
            'ERR' => self::parseErr($sourceSid, $target, $message),
            'INS' => self::parseIns($sourceSid, $target, $message),
            'DEL' => self::parseDel($sourceSid, $target, $message),
            'DRP' => self::parseDrp($sourceSid, $target, $message),
            'EXP' => self::parseExp($sourceSid, $target, $message),
            'MANIFEST' => self::parseManifest($sourceSid, $target, $message),
            'OCLG' => self::parseOclg($sourceSid, $target, $message),
            default => null,
        };
    }

    private static function parseHel(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if ('4' !== ($message->params[2] ?? null)) {
            return null;
        }
        $isAck = 'ACK' === strtoupper($message->params[3] ?? '');
        $argumentOffset = $isAck ? 4 : 3;
        $capabilityOffset = $isAck ? 6 : 5;
        $paramCount = count($message->params);
        if (($isAck && 7 !== $paramCount && 8 !== $paramCount) || (!$isAck && 6 !== $paramCount && 7 !== $paramCount)) {
            return null;
        }
        $propagator = $message->params[$argumentOffset] ?? '';
        $epoch = $message->params[$argumentOffset + 1] ?? '';
        $capabilities = self::parseHelCapabilities(array_values(array_slice($message->params, $capabilityOffset)));
        if ('' === $propagator || !self::validEpoch($epoch) || null === $capabilities) {
            return null;
        }

        return new UdbFrame($isAck ? UdbFrameKind::HelAck : UdbFrameKind::Hel, $sourceSid, $target, propagator: $propagator, epoch: $epoch, capabilities: $capabilities);
    }

    private static function parseInf(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (7 !== count($message->params) && 8 !== count($message->params)) {
            return null;
        }
        $roundId = self::nonZeroUnsigned($message->params[2]);
        $block = UdbBlock::fromLetter(strtoupper($message->params[3]));
        $digest = UdbChecksum::parse($message->params[4]);
        $recordCount = UdbPathCodec::parseUnsignedInt($message->params[5], 4294967295);
        $timestamp = UdbPathCodec::parseTimeT($message->params[6]);
        $watermark = self::optionalUnsigned($message, 7);
        if (null === $roundId || null === $block || null === $digest || null === $recordCount || null === $timestamp || false === $watermark) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Inf, $sourceSid, $target, roundId: $roundId, block: $block, checksum: $digest, timestamp: $timestamp, count: $recordCount, watermark: $watermark);
    }

    private static function parseRes(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (4 !== count($message->params)) {
            return null;
        }
        $roundId = self::nonZeroUnsigned($message->params[2]);
        $block = UdbBlock::fromLetter(strtoupper($message->params[3]));

        return null !== $roundId && null !== $block ? new UdbFrame(UdbFrameKind::Res, $sourceSid, $target, roundId: $roundId, block: $block) : null;
    }

    private static function parseBoundary(UdbFrameKind $kind, string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (6 !== count($message->params) && 7 !== count($message->params)) {
            return null;
        }
        $header = self::parseStagedHeader($kind, $sourceSid, $target, $message);
        $digest = UdbChecksum::parse($message->params[5]);
        $watermark = self::optionalUnsigned($message, 6);
        if (null === $header || null === $digest || false === $watermark) {
            return null;
        }

        return new UdbFrame($kind, $sourceSid, $target, roundId: $header->roundId, block: $header->block, txid: $header->txid, checksum: $digest, subcommand: $kind->value, watermark: $watermark);
    }

    private static function parsePut(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (count($message->params) < 6 || count($message->params) > 7) {
            return null;
        }
        $header = self::parseStagedHeader(UdbFrameKind::Put, $sourceSid, $target, $message);
        $path = $message->params[5];
        $value = $message->trailing ?? ($message->params[6] ?? null);
        if (null === $header || '' === $path || !UdbPathCodec::isCanonicalPath($path) || null === $value || '' === $value || strpbrk($value, "\r\n")) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Put, $sourceSid, $target, roundId: $header->roundId, block: $header->block, txid: $header->txid, path: $path, value: $value);
    }

    private static function parseStagedHeader(UdbFrameKind $kind, string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        $roundId = self::nonZeroUnsigned($message->params[2] ?? '');
        $block = UdbBlock::fromLetter(strtoupper($message->params[3] ?? ''));
        $txid = $message->params[4] ?? '';
        if (null === $roundId || null === $block || !UdbPathCodec::isValidTxid($txid)) {
            return null;
        }

        return new UdbFrame($kind, $sourceSid, $target, roundId: $roundId, block: $block, txid: $txid);
    }

    private static function parseErr(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (6 !== count($message->params)) {
            return null;
        }
        $errorCode = UdbPathCodec::parseUnsignedInt($message->params[3], 255);
        $correlation = self::nonZeroUnsigned($message->params[4]);
        $block = '0' === $message->params[5] ? null : UdbBlock::fromLetter(strtoupper($message->params[5]));
        if (null === $errorCode || null === $correlation || (null === $block && '0' !== $message->params[5])) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Err, $sourceSid, $target, roundId: $correlation, block: $block, subcommand: strtoupper($message->params[2]), errorCode: $errorCode);
    }

    private static function parseIns(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (count($message->params) < 5 || count($message->params) > 6) {
            return null;
        }
        $epoch = $message->params[2];
        $sequence = self::nonZeroUnsigned($message->params[3]);
        $path = $message->params[4];
        $value = $message->trailing ?? ($message->params[5] ?? null);
        if (!self::validEpoch($epoch) || null === $sequence || !self::isValidMutationPath($path) || null === $value || '' === $value || strpbrk($value, "\r\n")) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Ins, $sourceSid, $target, path: $path, value: $value, epoch: $epoch, sequence: $sequence);
    }

    private static function parseDel(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (5 !== count($message->params)) {
            return null;
        }
        $epoch = $message->params[2];
        $sequence = self::nonZeroUnsigned($message->params[3]);
        $path = $message->params[4];
        if (!self::validEpoch($epoch) || null === $sequence || !self::isValidMutationPath($path)) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Del, $sourceSid, $target, path: $path, epoch: $epoch, sequence: $sequence);
    }

    private static function parseDrp(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (5 !== count($message->params)) {
            return null;
        }
        $epoch = $message->params[2];
        $sequence = self::nonZeroUnsigned($message->params[3]);
        $block = UdbBlock::fromLetter(strtoupper($message->params[4]));
        if (!self::validEpoch($epoch) || null === $sequence || null === $block) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Drp, $sourceSid, $target, block: $block, epoch: $epoch, sequence: $sequence);
    }

    private static function parseExp(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (4 !== count($message->params)) {
            return null;
        }
        $expires = UdbPathCodec::parseTimeT($message->params[3]);
        if (null === $expires || $expires <= 0 || !self::isValidMutationPath($message->params[2]) || !str_starts_with(strtoupper($message->params[2]), 'K::')) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Exp, $sourceSid, $target, path: $message->params[2], expectedExpires: $expires);
    }

    private static function parseManifest(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        $operation = strtoupper($message->params[2] ?? '');
        $roundId = self::nonZeroUnsigned($message->params[3] ?? '');
        if (null === $roundId) {
            return null;
        }
        if ('REQ' === $operation && 4 === count($message->params)) {
            return new UdbFrame(UdbFrameKind::ManifestReq, $sourceSid, $target, roundId: $roundId);
        }
        if ('ACK' !== $operation || 8 !== count($message->params)) {
            return null;
        }
        $block = UdbBlock::fromLetter(strtoupper($message->params[4]));
        $count = UdbPathCodec::parseUnsignedInt($message->params[5], 4294967295);
        $digest = UdbChecksum::parse($message->params[6]);
        $watermark = UdbPathCodec::parseUnsigned($message->params[7]);
        if (null === $block || null === $count || null === $digest || null === $watermark) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::ManifestAck, $sourceSid, $target, roundId: $roundId, block: $block, checksum: $digest, count: $count, watermark: $watermark);
    }

    private static function parseOclg(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        $operation = strtoupper($message->params[2] ?? '');
        $epoch = $message->params[3] ?? '';
        $generation = self::nonZeroUnsigned($message->params[4] ?? '');
        if (!self::validEpoch($epoch) || null === $generation) {
            return null;
        }
        if ('BEGIN' === $operation && 8 === count($message->params)) {
            $status = strtoupper($message->params[5]);
            $count = UdbPathCodec::parseUnsignedInt($message->params[6], 1024);
            $digest = $message->params[7];
            if (!in_array($status, ['READY', 'INCOMPLETE'], true) || null === $count || !UdbOclgViewDigest::isValid($digest)) {
                return null;
            }

            return new UdbFrame(UdbFrameKind::OclgBegin, $sourceSid, $target, roundId: $generation, epoch: $epoch, checksum: $digest, status: $status, count: $count);
        }
        if ('ITEM' === $operation && 7 === count($message->params) && '' !== $message->params[5] && UdbOclgViewDigest::isValid($message->params[6])) {
            return new UdbFrame(UdbFrameKind::OclgItem, $sourceSid, $target, roundId: $generation, epoch: $epoch, path: $message->params[5], checksum: $message->params[6]);
        }
        if ('END' === $operation && 5 === count($message->params)) {
            return new UdbFrame(UdbFrameKind::OclgEnd, $sourceSid, $target, roundId: $generation, epoch: $epoch);
        }

        return null;
    }

    private static function stagedBoundary(string $verb, string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $digest, int|UdbUnsignedDecimal|null $watermark): string
    {
        return sprintf(':%s DB %s %s %s %s %s %s%s', $sid, $targetSid, $verb, $roundId, $block->letter(), $txid, self::digestOrEmpty($digest), self::optionalDecimal($watermark));
    }

    private static function optionalDecimal(int|UdbUnsignedDecimal|null $value): string
    {
        return null === $value ? '' : ' ' . $value;
    }

    private static function digestOrEmpty(string $digest): string
    {
        return UdbChecksum::parse($digest) ?? UdbChecksum::EMPTY;
    }

    private static function validEpoch(string $epoch): bool
    {
        return 1 === preg_match('/^[0-9a-f]{16}$/D', $epoch);
    }

    private static function nonZeroUnsigned(string $value): ?UdbUnsignedDecimal
    {
        $parsed = UdbPathCodec::parseUnsigned($value);

        return null === $parsed || $parsed->isZero() ? null : $parsed;
    }

    private static function optionalUnsigned(IRCMessage $message, int $offset): false|UdbUnsignedDecimal|null
    {
        if (!isset($message->params[$offset])) {
            return null;
        }

        return UdbPathCodec::parseUnsigned($message->params[$offset]) ?? false;
    }

    /** Mutation paths must be "<block>::<canonical encoded remainder>". */
    private static function isValidMutationPath(string $path): bool
    {
        if (1 !== strpos($path, '::')) {
            return false;
        }
        $block = UdbBlock::fromLetter(strtoupper($path[0]));
        $remainder = substr($path, 3);

        return null !== $block && '' !== $remainder && UdbPathCodec::isCanonicalPath($remainder);
    }

    /**
     * @param array<string> $capabilities
     *
     * @return list<string>|null
     */
    private static function parseHelCapabilities(array $capabilities): ?array
    {
        assert([] !== $capabilities);
        $normalized = array_values(array_map(strtoupper(...), $capabilities));

        return 'OCL' === $normalized[0] && (!isset($normalized[1]) || 'OCLG' === $normalized[1]) ? $normalized : null;
    }
}
