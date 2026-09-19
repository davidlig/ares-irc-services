<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;

use function explode;
use function strlen;
use function substr;

/**
 * Renders UDB wire lines for the debug log in both directions.
 *
 * The wire grammar and its validation live only in UdbWireCodec: a line is
 * parsed with the same codec used on the wire, and the parsed record identity
 * decides whether the value is secret. UdbSchema marks N::<nick>::pass and
 * S::encryption_key as secret, so those values are replaced by the redaction
 * marker. Frames without a record value (HEL, INF, RES, BEGIN/END/ACK, ERR,
 * DEL, DRP, EXP, MANIFEST, OCLG) stay fully visible.
 *
 * A value is only logged verbatim when its parsed record identity is
 * positively non-secret; anything else is masked, so an unclassified frame
 * can never smuggle a secret into the logs. Lines that do not parse are
 * returned untouched because the logging paths never mirror unparsed frames.
 */
final class UdbWireLogRedactor
{
    private const string MASK = '<redacted>';

    public static function redact(string $line): string
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine($line));

        return null === $frame ? $line : self::redactFrame($frame, $line);
    }

    public static function redactFrame(UdbFrame $frame, string $line): string
    {
        if (null === $frame->value || self::isPositivelyNonSecret($frame)) {
            return $line;
        }

        return substr($line, 0, strlen($line) - strlen($frame->value)) . self::MASK;
    }

    /** True only when the parsed record identity is known and not secret. */
    private static function isPositivelyNonSecret(UdbFrame $frame): bool
    {
        if (UdbFrameKind::Put === $frame->kind && null !== $frame->block && null !== $frame->path) {
            $components = self::components($frame->path);

            return null !== $components && !UdbSchema::isSecret($frame->block, $components);
        }

        if (UdbFrameKind::Ins === $frame->kind && null !== $frame->path && '' !== $frame->path) {
            $block = UdbBlock::fromLetter($frame->path[0]);
            $components = self::components(substr($frame->path, 3));

            return null !== $block && null !== $components && !UdbSchema::isSecret($block, $components);
        }

        return false;
    }

    /** @return list<string>|null Raw components, or null when the encoded path is not canonical. */
    private static function components(string $encodedPath): ?array
    {
        $components = [];
        foreach (explode('::', $encodedPath) as $component) {
            $decoded = UdbPathCodec::decodeComponent($component);
            if (null === $decoded) {
                return null;
            }

            $components[] = $decoded;
        }

        return $components;
    }
}
