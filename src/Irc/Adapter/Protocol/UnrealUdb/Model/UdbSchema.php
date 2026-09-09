<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;

use function array_map;
use function array_slice;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function in_array;
use function ltrim;
use function ord;
use function preg_match;
use function str_contains;
use function str_split;
use function str_starts_with;
use function strcasecmp;
use function strcmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * Complete UDB 4 record schema validation for all six blocks.
 *
 * Mirrors udb_record_validate() and its helpers in the UnrealIRCd UDB module
 * (udb_core.c.inc, udb_config.c.inc, udb_lines.c.inc) so a record accepted
 * here is always accepted by the IRCd, and one rejected here can never
 * diverge the authoritative store from the network.
 *
 * Limits mirror udb_internal.h / struct.h:
 *   NICKLEN 30, CHANNELLEN 32, HOSTLEN 63, UDB_TKL_MASK_COMPONENT_MAX 127,
 *   UDB_SPAMFILTER_PATTERN_MAX 3072, MAXMODEPARAMS 12, mode/snomask/oper 64.
 */
final class UdbSchema
{
    public const int NICK_MAX = 30;

    public const int CHANNEL_MAX = 32;

    public const int HOST_MAX = 63;

    public const int TKL_MASK_COMPONENT_MAX = 127;

    public const int SPAMFILTER_PATTERN_MAX = 3072;

    public const int MAXMODEPARAMS = 12;

    /**
     * User modes registered by UnrealIRCd core + the bundled usermodes modules
     * (modules.default.conf). 'o' is strictly forbidden by UDB (N::modes).
     * Extend this list only when the IRCd loads additional usermode modules.
     */
    public const array USER_MODES = ['i', 's', 'w', 'B', 'S', 'T', 'G', 'W', 'p', 'q', 'R', 'Z', 'D'];

    private const array NICK_KEYS = [
        'access', 'pass', 'vhost', 'forbid', 'suspended', 'oper', 'challenge', 'modes', 'snomasks', 'swhois',
    ];

    private const array CHANNEL_KEYS = [
        'founder', 'modes', 'topic', 'access', 'forbid', 'suspended', 'pass', 'challenge', 'options',
    ];

    private const array IP_KEYS = ['clones', 'nolines', 'host'];

    private const array SETTINGS_KEYS = [
        'encryption_key', 'suffix', 'nickserv', 'chanserv', 'ipserv', 'clones', 'quit_ips', 'quit_clones',
        'flood', 'propagator',
    ];

    private const array LINK_KEYS = ['options'];

    private const array TKL_TYPES = ['G', 'Z', 'S', 'Q', 'F'];

    /** Spamfilter targets (spamfiltertargettable, strcmp = case-sensitive). */
    private const array SPAMFILTER_TARGETS = [
        'channel', 'private', 'private-notice', 'channel-notice', 'part', 'quit',
        'dcc', 'user', 'away', 'topic', 'message-tag', 'raw',
    ];

    /** Ban actions (banacttable, strcasecmp = case-insensitive, config-only included). */
    private const array BAN_ACTIONS = [
        'kill', 'soft-kill', 'tempshun', 'soft-tempshun', 'shun', 'soft-shun', 'kline', 'soft-kline',
        'zline', 'gline', 'soft-gline', 'gzline', 'block', 'soft-block', 'dccblock', 'soft-dccblock',
        'viruschan', 'soft-viruschan', 'warn', 'soft-warn', 'set', 'report', 'stop',
    ];

    /** Channel modes carrying their rank parameter (CMODE_MEMBER). */
    private const array CHAN_MEMBER_MODES = ['q', 'a', 'o', 'h', 'v'];

    /** Channel modes taking a parameter when added (incl. list modes b/e/I). */
    private const array CHAN_PARAM_ON_ADD = ['k', 'l', 'f', 'L', 'F', 'H', 'b', 'e', 'I'];

    /** Channel modes needing their parameter when removed. */
    private const array CHAN_UNSET_WITH_PARAM = ['k', 'f', 'L', 'F', 'H', 'b', 'e', 'I'];

    /** Simple channel modes without parameters (core + bundled chanmodes modules). */
    private const array CHAN_SIMPLE_MODES = [
        'i', 's', 'p', 'm', 'n', 't', 'c', 'C', 'D', 'G', 'K', 'M', 'N', 'O', 'Q', 'R', 'S', 'T', 'V', 'z', 'Z', 'r', 'P',
    ];

    /**
     * Validates a record for the given block.
     *
     * @param list<string> $components Raw (decoded) path components WITHOUT the block letter
     * @param string       $value      Record value (never empty: INS always carries one)
     */
    public static function validate(UdbBlock $block, array $components, string $value): bool
    {
        if ([] === $components || count($components) > 3) {
            return false;
        }

        if ('' === $value || strlen($value) > 4096 || str_contains($value, "\r") || str_contains($value, "\n")) {
            return false;
        }

        return match ($block) {
            UdbBlock::Nicks => self::validateNicks($components, $value),
            UdbBlock::Channels => self::validateChannels($components, $value),
            UdbBlock::Ips => self::validateIps($components, $value),
            UdbBlock::Settings => self::validateSettings($components, $value),
            UdbBlock::Links => self::validateLinks($components, $value),
            UdbBlock::Lines => self::validateLines($components, $value),
        };
    }

    /**
     * True when the record value must be redacted in logs and audit trails
     * (mirrors udb_mutation_value_is_secret).
     *
     * @param list<string> $components Raw path components WITHOUT the block letter
     */
    public static function isSecret(UdbBlock $block, array $components): bool
    {
        $key = strtolower($components[count($components) - 1] ?? '');

        return match ($block) {
            UdbBlock::Nicks => 2 === count($components) && 'pass' === $key,
            UdbBlock::Channels => 2 === count($components) && ('pass' === $key || 'challenge' === $key),
            UdbBlock::Settings => 1 === count($components) && 'encryption_key' === $key,
            default => false,
        };
    }

    /** @param list<string> $components */
    private static function validateNicks(array $components, string $value): bool
    {
        $depth = count($components);
        if (1 === $depth) {
            return self::nickName($components[0]);
        }

        if (2 !== $depth || !self::nickName($components[0]) || !in_array(strtolower($components[1]), self::NICK_KEYS, true)) {
            return false;
        }

        return match (strtolower($components[1])) {
            'access', 'forbid', 'suspended', 'swhois' => self::stringRecord($value),
            'pass' => self::passwordHash($value),
            'vhost' => self::vhost($value),
            'oper' => self::operName($value),
            'challenge' => self::challenge($value),
            'modes' => self::userModes($value),
            default => self::snomasks($value),
        };
    }

    /** @param list<string> $components */
    private static function validateChannels(array $components, string $value): bool
    {
        $depth = count($components);
        if (1 === $depth) {
            return self::channelName($components[0]);
        }

        if (!self::channelName($components[0]) || !in_array(strtolower($components[1]), self::CHANNEL_KEYS, true)) {
            return false;
        }

        if (2 === $depth) {
            return match (strtolower($components[1])) {
                'founder' => self::nickName($value),
                'modes' => self::channelModes($value),
                'topic', 'forbid', 'suspended' => self::stringRecord($value),
                'pass' => self::passwordHash($value),
                'challenge' => self::challenge($value),
                'options' => self::numericRecord($value),
                default => false,
            };
        }

        // depth 3: only access has children, keyed by nick.
        return 'access' === strtolower($components[1])
            && self::nickName($components[2])
            && ('*' === $value[0] ? self::numericRecord($value) : self::stringRecord($value));
    }

    /** @param list<string> $components */
    private static function validateIps(array $components, string $value): bool
    {
        $depth = count($components);
        if (1 === $depth) {
            return self::ipHostMask($components[0]);
        }

        if (2 !== $depth || !self::ipHostMask($components[0]) || !in_array(strtolower($components[1]), self::IP_KEYS, true)) {
            return false;
        }

        return match (strtolower($components[1])) {
            'clones' => self::cloneLimit($value),
            'nolines' => self::nolines($value),
            default => self::vhost($value),
        };
    }

    /** @param list<string> $components */
    private static function validateSettings(array $components, string $value): bool
    {
        if (1 !== count($components) || !in_array(strtolower($components[0]), self::SETTINGS_KEYS, true)) {
            return false;
        }

        return match (strtolower($components[0])) {
            'clones' => self::cloneLimit($value),
            'quit_ips', 'quit_clones' => self::stringRecord($value),
            'encryption_key' => self::encryptionKey($value),
            'suffix' => self::suffix($value),
            'nickserv', 'chanserv', 'ipserv' => self::serviceMask($value),
            'flood' => self::flood($value),
            default => self::propagatorList($value),
        };
    }

    /** @param list<string> $components */
    private static function validateLinks(array $components, string $value): bool
    {
        $depth = count($components);
        if (1 === $depth) {
            return self::serverName($components[0]);
        }

        return 2 === $depth
            && self::serverName($components[0])
            && in_array(strtolower($components[1]), self::LINK_KEYS, true)
            && self::numericRecord($value);
    }

    /** @param list<string> $components */
    private static function validateLines(array $components, string $value): bool
    {
        $depth = count($components);
        $type = $components[0];

        if ($depth < 2 || $depth > 3 || 1 !== strlen($type) || !in_array($type, self::TKL_TYPES, true)) {
            return false;
        }

        if ('F' === $type) {
            return 3 === $depth && self::spamfilterPattern($components[1]) && self::spamfilterSubkey($components[2], $value);
        }

        // G, Z, S require a user@host mask; Q bans a nick pattern.
        if ('Q' !== $type && !self::lineMask($components[1])) {
            return false;
        }

        if (2 === $depth) {
            return self::nonEmptyNoStar($value);
        }

        return match (strtolower($components[2])) {
            'reason' => self::nonEmptyNoStar($value),
            'duration' => self::numericRecord($value),
            default => false,
        };
    }

    private static function spamfilterSubkey(string $subkey, string $value): bool
    {
        return match (strtolower($subkey)) {
            'type' => in_array($value, self::SPAMFILTER_TARGETS, true),
            'action' => self::inArrayCaseInsensitive($value, self::BAN_ACTIONS),
            'duration' => self::numericRecord($value),
            'reason' => self::nonEmptyNoStar($value),
            default => false,
        };
    }

    // === Value validators ====================================================

    /** UDB_VAL_STRING at depth 2 / block S: non-empty, no leading '*'. */
    private static function stringRecord(string $value): bool
    {
        return '' !== $value && !str_starts_with($value, '*');
    }

    /** Non-empty string that must not start with '*' (K root values and reasons). */
    private static function nonEmptyNoStar(string $value): bool
    {
        return '' !== $value && !str_starts_with($value, '*');
    }

    /** UDB_VAL_NUMERIC: '*' followed by a strict unsigned-long decimal. */
    private static function numericRecord(string $value): bool
    {
        return str_starts_with($value, '*') && null !== UdbUnsignedDecimal::parse(substr($value, 1));
    }

    private static function cloneLimit(string $value): bool
    {
        if (!self::numericRecord($value)) {
            return false;
        }

        $digits = ltrim(substr($value, 1), '0');

        return strlen($digits) < 10 || (10 === strlen($digits) && strcmp($digits, '2147483647') <= 0);
    }

    private static function passwordHash(string $value): bool
    {
        if (str_starts_with($value, 'argon2id:$argon2id$')) {
            return true;
        }

        if (str_starts_with($value, 'crypt:')) {
            return strlen($value) > 6;
        }

        return 1 === preg_match('/^sha256:[0-9a-fA-F]{64}\z/', $value);
    }

    private static function vhost(string $value): bool
    {
        return '' !== $value && strlen($value) <= self::HOST_MAX && !preg_match('/[ \t\r\n]/', $value);
    }

    private static function operName(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $value);
    }

    private static function challenge(string $value): bool
    {
        return self::inArrayCaseInsensitive($value, ['argon2id', 'sha256', 'crypt']);
    }

    private static function userModes(string $value): bool
    {
        $length = strlen($value);
        if ('' === $value || $length > 64) {
            return false;
        }

        $letters = 0;
        foreach (str_split($value) as $char) {
            if ('+' === $char || '-' === $char) {
                continue;
            }

            if (!ctype_alpha($char) || 'o' === $char || !in_array($char, self::USER_MODES, true)) {
                return false;
            }
            ++$letters;
        }

        return $letters > 0;
    }

    private static function snomasks(string $value): bool
    {
        $length = strlen($value);
        if ('' === $value || $length > 64) {
            return false;
        }

        $letters = 0;
        foreach (str_split($value) as $char) {
            if ('+' === $char || '-' === $char) {
                continue;
            }

            if (!ctype_alpha($char)) {
                return false;
            }
            ++$letters;
        }

        return $letters > 0;
    }

    /**
     * Channel modes record: "+modes params" with strict parameter accounting.
     * Mirrors udb_channel_modes_record_valid.
     */
    private static function channelModes(string $value): bool
    {
        if ('' === $value || strlen($value) >= 512) {
            return false;
        }

        $tokens = explode(' ', $value);
        $modes = $tokens[0];
        $params = array_slice($tokens, 1);

        $expectedParams = 0;
        $modeLetters = 0;
        $what = 'add';

        foreach (str_split($modes) as $char) {
            if ('+' === $char) {
                $what = 'add';

                continue;
            }
            if ('-' === $char) {
                $what = 'del';

                continue;
            }

            if (!self::isChannelModeLetter($char)) {
                return false;
            }

            ++$modeLetters;
            if (in_array($char, self::CHAN_MEMBER_MODES, true)) {
                ++$expectedParams;
            } elseif (('add' === $what && in_array($char, self::CHAN_PARAM_ON_ADD, true))
                || ('del' === $what && in_array($char, self::CHAN_UNSET_WITH_PARAM, true))) {
                ++$expectedParams;
            }
        }

        if (0 === $modeLetters || $expectedParams > self::MAXMODEPARAMS) {
            return false;
        }

        $actualParams = 0;
        foreach ($params as $param) {
            if ('' === $param) {
                return false;
            }
            ++$actualParams;
        }

        return $expectedParams === $actualParams;
    }

    private static function isChannelModeLetter(string $char): bool
    {
        return in_array($char, self::CHAN_MEMBER_MODES, true)
            || in_array($char, self::CHAN_PARAM_ON_ADD, true)
            || in_array($char, self::CHAN_SIMPLE_MODES, true);
    }

    private static function nolines(string $value): bool
    {
        $length = strlen($value);

        if ('' === $value || $length > 16) {
            return false;
        }

        foreach (str_split($value) as $char) {
            if (!str_contains('GZQSTmc', $char)) {
                return false;
            }
        }

        return true;
    }

    private static function encryptionKey(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-fA-F]{64}\z/', $value);
    }

    /** Host suffix for cloaking: ".label..." with domain-label rules. */
    private static function suffix(string $value): bool
    {
        $length = strlen($value);
        if ('' === $value || $length > self::HOST_MAX - 32 || '.' !== $value[0]) {
            return false;
        }

        $label = substr($value, 1);
        if ('' === $label) {
            return false;
        }

        $cursor = 0;
        foreach (str_split($label) as $char) {
            $isAlnum = ctype_alnum($char);
            if (!$isAlnum && '.' !== $char && '-' !== $char) {
                return false;
            }
            if ('.' === $char && (0 === $cursor || '-' === $label[$cursor - 1])) {
                return false;
            }
            if ('-' === $char && 0 === $cursor) {
                return false;
            }
            ++$cursor;
        }

        return '-' !== $label[$cursor - 1] && '.' !== $label[$cursor - 1];
    }

    /** Service bot mask: nick!ident@host with all parts non-empty. */
    private static function serviceMask(string $value): bool
    {
        if ('' === $value || strlen($value) > 4096 || preg_match('/[ \t\r\n]/', $value)) {
            return false;
        }

        $bang = strpos($value, '!');
        if (false === $bang || 0 === $bang) {
            return false;
        }

        $at = strpos($value, '@', $bang + 1);

        return false !== $at && $bang + 1 !== $at && $at + 1 < strlen($value);
    }

    /** Flood setting "V:S" with both parts strict unsigned ints >= 1. */
    private static function flood(string $value): bool
    {
        $parts = explode(':', $value);

        if (2 !== count($parts)) {
            return false;
        }

        foreach ($parts as $part) {
            if (1 !== preg_match('/^[0-9]{1,10}\z/', $part) || (int) $part < 1 || (int) $part > 2147483647) {
                return false;
            }
        }

        return true;
    }

    /** Comma-separated list of valid server names (spaces allowed around commas). */
    private static function propagatorList(string $value): bool
    {
        if ('' === $value || strlen($value) > 4096 || preg_match('/[\r\n\t]/', $value)) {
            return false;
        }

        $names = array_map(static fn (string $name): string => trim($name, ' '), explode(',', $value));

        foreach ($names as $name) {
            if (!self::serverName($name)) {
                return false;
            }
        }

        return true;
    }

    /** RFC 4648 canonical base64 (optionally "b64:"-prefixed) spamfilter pattern. */
    private static function spamfilterPattern(string $stored): bool
    {
        if ('' === $stored || strlen($stored) > self::SPAMFILTER_PATTERN_MAX) {
            return false;
        }

        if (!str_starts_with($stored, 'b64:')) {
            return true;
        }

        $encoded = substr($stored, 4);
        $length = strlen($encoded);

        if (0 === $length || 0 !== $length % 4 || $length > 4096) {
            return false;
        }

        $decoded = base64_decode($encoded, true);
        if (false === $decoded || '' === $decoded || strlen($decoded) > self::SPAMFILTER_PATTERN_MAX) {
            return false;
        }

        if (str_contains($decoded, "\0")) {
            return false;
        }

        return base64_encode($decoded) === $encoded;
    }

    // === Name / path component validators ===================================

    private static function nickName(string $name): bool
    {
        // No leading digit or hyphen (udb_nick_name_valid).
        return 1 === preg_match('/^[A-Za-z\[\]\\\\`_^{|}][A-Za-z0-9\[\]\\\\`_^{|}\-]{0,' . (self::NICK_MAX - 1) . '}\z/', $name);
    }

    private static function channelName(string $name): bool
    {
        $length = strlen($name);

        if ($length < 2 || $length > self::CHANNEL_MAX) {
            return false;
        }

        return 1 === preg_match('/^[#&][^\x00-\x20:,\x07]+\z/', $name);
    }

    private static function ipHostMask(string $mask): bool
    {
        $length = strlen($mask);

        if ('' === $mask || $length > self::HOST_MAX) {
            return false;
        }

        foreach (str_split($mask) as $char) {
            if (ord($char) <= 32 || ord($char) > 126) {
                return false;
            }
        }

        return true;
    }

    /** user@host (each component 1..127, single @) or a bare host/pattern (<= 127). */
    private static function lineMask(string $mask): bool
    {
        $at = strpos($mask, '@');

        if (false === $at) {
            return '' !== $mask && strlen($mask) <= self::TKL_MASK_COMPONENT_MAX;
        }

        $userLength = $at;
        $host = substr($mask, $at + 1);

        return $userLength > 0
            && $userLength <= self::TKL_MASK_COMPONENT_MAX
            && '' !== $host
            && strlen($host) <= self::TKL_MASK_COMPONENT_MAX
            && !str_contains($host, '@');
    }

    /** Hostname: dot-separated labels of alphanumerics/hyphen, no leading hyphen. */
    private static function serverName(string $name): bool
    {
        if ('' === $name || strlen($name) > self::HOST_MAX || preg_match('/[ \t\r\n]/', $name)) {
            return false;
        }

        foreach (explode('.', $name) as $label) {
            if ('' === $label || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*\z/', $label)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $haystack */
    private static function inArrayCaseInsensitive(string $needle, array $haystack): bool
    {
        foreach ($haystack as $candidate) {
            if (0 === strcasecmp($needle, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
