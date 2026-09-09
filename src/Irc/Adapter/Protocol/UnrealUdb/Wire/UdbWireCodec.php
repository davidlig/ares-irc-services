<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;

use function array_slice;
use function assert;
use function count;
use function implode;
use function in_array;
use function sprintf;
use function strpbrk;
use function strpos;
use function strtoupper;
use function substr;

use const PHP_INT_MAX;

/**
 * Wire codec for the current UDB 4 DB command grammar.
 *
 * Outbound builders and an exact inbound parser for:
 *   DB <target> HEL 4 <propagator> <epoch16> OCL [OCLG]
 *   DB <target> HEL 4 ACK <propagator> <epoch16> OCL [OCLG]
 *   DB <target> INF   <round> <block> <crc32> <modified-at>
 *   DB <target> RES   <round> <block>
 *   DB <target> BEGIN <round> <block> <txid> <crc32>
 *   DB <target> PUT   <round> <block> <txid> <encoded-path> <value>
 *   DB <target> END   <round> <block> <txid> <crc32>
 *   DB <target> ACK   <round> <block> <txid> <crc32>
 *   DB <target> ERR   <subcmd> <code> <round-or-correlation> <block>
 *   DB * INS <block>::<encoded-path> <value>
 *   DB * DEL <block>::<encoded-path>
 *
 * Values with spaces are always sent as an IRC trailing parameter (" :value");
 * the receiver's parser folds the trailing into the last parameter, matching
 * the C implementation's parv layout.
 */
final class UdbWireCodec
{
    /**
     * @param list<string> $capabilities
     */
    public static function hel(string $sid, string $targetSid, string $propagator, string $epoch, array $capabilities = ['OCL']): string
    {
        return sprintf(':%s DB %s HEL 4 %s %s %s', $sid, $targetSid, $propagator, $epoch, implode(' ', $capabilities));
    }

    /**
     * @param list<string> $capabilities
     */
    public static function helAck(string $sid, string $targetSid, string $propagator, string $epoch, array $capabilities = ['OCL']): string
    {
        return sprintf(':%s DB %s HEL 4 ACK %s %s %s', $sid, $targetSid, $propagator, $epoch, implode(' ', $capabilities));
    }

    public static function inf(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $checksum, int $modifiedAt): string
    {
        return sprintf(':%s DB %s INF %s %s %s %d', $sid, $targetSid, $roundId, $block->letter(), self::checksumOrEmpty($checksum), $modifiedAt);
    }

    public static function res(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block): string
    {
        return sprintf(':%s DB %s RES %s %s', $sid, $targetSid, $roundId, $block->letter());
    }

    public static function begin(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $checksum): string
    {
        return sprintf(':%s DB %s BEGIN %s %s %s %s', $sid, $targetSid, $roundId, $block->letter(), $txid, self::checksumOrEmpty($checksum));
    }

    public static function put(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $encodedPath, string $value): string
    {
        return sprintf(':%s DB %s PUT %s %s %s %s :%s', $sid, $targetSid, $roundId, $block->letter(), $txid, $encodedPath, $value);
    }

    public static function end(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $checksum): string
    {
        return sprintf(':%s DB %s END %s %s %s %s', $sid, $targetSid, $roundId, $block->letter(), $txid, self::checksumOrEmpty($checksum));
    }

    public static function ack(string $sid, string $targetSid, int|UdbUnsignedDecimal $roundId, UdbBlock $block, string $txid, string $checksum): string
    {
        return sprintf(':%s DB %s ACK %s %s %s %s', $sid, $targetSid, $roundId, $block->letter(), $txid, self::checksumOrEmpty($checksum));
    }

    public static function err(string $sid, string $targetSid, string $subcommand, int $errorCode, int|UdbUnsignedDecimal $roundId, ?UdbBlock $block): string
    {
        return sprintf(':%s DB %s ERR %s %d %s %s', $sid, $targetSid, $subcommand, $errorCode, $roundId, null !== $block ? $block->letter() : '0');
    }

    public static function ins(string $sid, string $blockLetter, string $encodedPath, string $value): string
    {
        return sprintf(':%s DB * INS %s::%s :%s', $sid, $blockLetter, $encodedPath, $value);
    }

    public static function del(string $sid, string $blockLetter, string $encodedPath): string
    {
        return sprintf(':%s DB * DEL %s::%s', $sid, $blockLetter, $encodedPath);
    }

    /**
     * Parses an inbound DB message into a typed frame. Returns null for any
     * frame that does not match the current grammar exactly; callers must
     * ignore (and log) null results instead of guessing.
     */
    public static function parse(IRCMessage $message): ?UdbFrame
    {
        if ('DB' !== strtoupper($message->command) || null === $message->prefix || count($message->params) < 2) {
            return null;
        }

        $sourceSid = $message->prefix;
        $target = $message->params[0];
        $subcommand = strtoupper($message->params[1]);

        return match ($subcommand) {
            'HEL' => self::parseHel($sourceSid, $target, $message),
            'INF' => self::parseInf($sourceSid, $target, $message),
            'RES' => self::parseRes($sourceSid, $target, $message),
            'BEGIN' => self::parseBegin($sourceSid, $target, $message),
            'PUT' => self::parsePut($sourceSid, $target, $message),
            'END' => self::parseEnd('END', UdbFrameKind::End, $sourceSid, $target, $message),
            'ACK' => self::parseEnd('ACK', UdbFrameKind::Ack, $sourceSid, $target, $message),
            'ERR' => self::parseErr($sourceSid, $target, $message),
            'INS' => self::parseIns($sourceSid, $target, $message),
            'DEL' => self::parseDel($sourceSid, $target, $message),
            'DRP' => self::parseDrp($sourceSid, $target, $message),
            'OPT' => self::parseOpt($sourceSid, $target, $message),
            'OCLG' => self::parseOclg($sourceSid, $target, $message),
            default => null,
        };
    }

    private static function parseOclg(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        $operation = strtoupper($message->params[2] ?? '');
        $epoch = $message->params[3] ?? '';
        $generation = UdbPathCodec::parseUnsigned($message->params[4] ?? '');
        if (1 !== preg_match('/^[0-9a-f]{16}$/D', $epoch) || null === $generation || $generation->isZero()) {
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

        if ('ITEM' === $operation && 7 === count($message->params)) {
            $name = $message->params[5];
            $digest = $message->params[6];
            if ('' === $name || !UdbOclgViewDigest::isValid($digest)) {
                return null;
            }

            return new UdbFrame(UdbFrameKind::OclgItem, $sourceSid, $target, roundId: $generation, epoch: $epoch, path: $name, checksum: $digest);
        }

        if ('END' === $operation && 5 === count($message->params)) {
            return new UdbFrame(UdbFrameKind::OclgEnd, $sourceSid, $target, roundId: $generation, epoch: $epoch);
        }

        return null;
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
        if (($isAck && (7 !== $paramCount && 8 !== $paramCount)) || (!$isAck && (6 !== $paramCount && 7 !== $paramCount))) {
            return null;
        }

        $propagator = $message->params[$argumentOffset] ?? '';
        $epoch = $message->params[$argumentOffset + 1] ?? '';
        $capabilities = self::parseHelCapabilities(array_values(array_slice($message->params, $capabilityOffset)));
        if ('' === $propagator || 1 !== preg_match('/^[0-9a-f]{16}$/D', $epoch) || null === $capabilities) {
            return null;
        }

        return new UdbFrame(
            $isAck ? UdbFrameKind::HelAck : UdbFrameKind::Hel,
            $sourceSid,
            $target,
            propagator: $propagator,
            epoch: $epoch,
            capabilities: $capabilities,
        );
    }

    private static function parseInf(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (6 !== count($message->params)) {
            return null;
        }

        $roundId = UdbPathCodec::parseUnsigned($message->params[2]);
        $block = UdbBlock::fromLetter(strtoupper($message->params[3]));
        $checksum = UdbChecksum::parse($message->params[4]);
        $timestamp = UdbPathCodec::parseUnsignedInt($message->params[5], PHP_INT_MAX);
        if (null === $roundId || $roundId->isZero() || null === $checksum || null === $timestamp || null === $block) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Inf, $sourceSid, $target, roundId: $roundId, block: $block, checksum: $checksum, timestamp: $timestamp);
    }

    private static function parseRes(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (4 !== count($message->params)) {
            return null;
        }

        $roundId = UdbPathCodec::parseUnsigned($message->params[2]);
        if (null === $roundId || $roundId->isZero()) {
            return null;
        }

        $block = UdbBlock::fromLetter(strtoupper($message->params[3]));
        if (null === $block) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Res, $sourceSid, $target, roundId: $roundId, block: $block);
    }

    private static function parseBegin(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (6 !== count($message->params)) {
            return null;
        }

        $frame = self::parseStagedHeader(UdbFrameKind::Begin, $sourceSid, $target, $message);
        if (null === $frame) {
            return null;
        }

        $checksum = UdbChecksum::parse($message->params[5]);
        if (null === $checksum) {
            return null;
        }

        return new UdbFrame(
            UdbFrameKind::Begin,
            $sourceSid,
            $target,
            roundId: $frame->roundId,
            block: $frame->block,
            txid: $frame->txid,
            checksum: $checksum,
        );
    }

    private static function parsePut(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        // PUT round block txid path :value | PUT round block txid path *num
        if (count($message->params) < 6 || count($message->params) > 7) {
            return null;
        }

        $frame = self::parseStagedHeader(UdbFrameKind::Put, $sourceSid, $target, $message);
        if (null === $frame) {
            return null;
        }

        $path = $message->params[5];
        if ('' === $path || !UdbPathCodec::isCanonicalPath($path)) {
            return null;
        }

        $value = $message->trailing ?? ($message->params[6] ?? null);
        if (null === $value || '' === $value || strpbrk($value, "\r\n")) {
            return null;
        }

        return new UdbFrame(
            UdbFrameKind::Put,
            $sourceSid,
            $target,
            roundId: $frame->roundId,
            block: $frame->block,
            txid: $frame->txid,
            path: $path,
            value: $value,
        );
    }

    private static function parseEnd(string $subcommand, UdbFrameKind $kind, string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (6 !== count($message->params) && 5 !== count($message->params)) {
            return null;
        }

        $frame = self::parseStagedHeader($kind, $sourceSid, $target, $message);
        if (null === $frame) {
            return null;
        }

        $checksum = UdbChecksum::parse($message->params[5] ?? $message->trailing ?? '');
        if (null === $checksum) {
            return null;
        }

        return new UdbFrame(
            $kind,
            $sourceSid,
            $target,
            roundId: $frame->roundId,
            block: $frame->block,
            txid: $frame->txid,
            checksum: $checksum,
            subcommand: $subcommand,
        );
    }

    private static function parseStagedHeader(UdbFrameKind $kind, string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        $roundId = UdbPathCodec::parseUnsigned($message->params[2] ?? '');
        $block = UdbBlock::fromLetter(strtoupper($message->params[3] ?? ''));
        $txid = $message->params[4] ?? '';
        if (null === $roundId || $roundId->isZero() || null === $block || !UdbPathCodec::isValidTxid($txid)) {
            return null;
        }

        return new UdbFrame($kind, $sourceSid, $target, roundId: $roundId, block: $block, txid: $txid);
    }

    private static function parseErr(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        // ERR <subcmd> <code 0-255> <round-or-correlation> <block-letter|0>
        if (6 !== count($message->params)) {
            return null;
        }

        $errorCode = UdbPathCodec::parseUnsignedInt($message->params[3], 255);
        $roundId = UdbPathCodec::parseUnsigned($message->params[4]);
        if (null === $errorCode || null === $roundId || $roundId->isZero()) {
            return null;
        }

        $blockLetter = $message->params[5];
        $block = '0' === $blockLetter ? null : UdbBlock::fromLetter(strtoupper($blockLetter));
        if (null !== $block || '0' === $blockLetter) {
            return new UdbFrame(
                UdbFrameKind::Err,
                $sourceSid,
                $target,
                roundId: $roundId,
                block: $block,
                subcommand: strtoupper($message->params[2]),
                errorCode: $errorCode,
            );
        }

        return null;
    }

    private static function parseIns(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (count($message->params) < 3) {
            return null;
        }

        $path = $message->params[2];
        if ('' === $path || !self::isValidMutationPath($path)) {
            return null;
        }

        $value = $message->trailing ?? implode(' ', array_slice($message->params, 3));
        if ('' === $value || strpbrk($value, "\r\n")) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Ins, $sourceSid, $target, path: $path, value: $value);
    }

    private static function parseDel(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (3 !== count($message->params)) {
            return null;
        }

        $path = $message->params[2];
        if ('' === $path || !self::isValidMutationPath($path)) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Del, $sourceSid, $target, path: $path);
    }

    private static function parseDrp(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (3 !== count($message->params)) {
            return null;
        }

        $block = UdbBlock::fromLetter(strtoupper($message->params[2]));
        if (null === $block) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Drp, $sourceSid, $target, block: $block);
    }

    private static function parseOpt(string $sourceSid, string $target, IRCMessage $message): ?UdbFrame
    {
        if (count($message->params) < 3 || count($message->params) > 4) {
            return null;
        }

        $block = UdbBlock::fromLetter(strtoupper($message->params[2]));
        if (null === $block) {
            return null;
        }

        return new UdbFrame(UdbFrameKind::Opt, $sourceSid, $target, block: $block, modifiedAt: $message->params[3] ?? null);
    }

    /** Mutation paths must be "<block>::<canonical encoded remainder>". */
    private static function isValidMutationPath(string $path): bool
    {
        $separator = strpos($path, '::');
        if (1 !== $separator) {
            return false;
        }

        $block = UdbBlock::fromLetter(strtoupper($path[0]));
        $remainder = substr($path, 3);

        return null !== $block && '' !== $remainder && UdbPathCodec::isCanonicalPath($remainder);
    }

    private static function checksumOrEmpty(string $checksum): string
    {
        return UdbChecksum::parse($checksum) ?? UdbChecksum::EMPTY;
    }

    /**
     * @param list<string> $capabilities
     *
     * @return list<string>|null
     */
    private static function parseHelCapabilities(array $capabilities): ?array
    {
        assert([] !== $capabilities);

        $normalized = array_map(strtoupper(...), $capabilities);
        if ('OCL' !== $normalized[0] || (isset($normalized[1]) && 'OCLG' !== $normalized[1])) {
            return null;
        }

        return $normalized;
    }
}
