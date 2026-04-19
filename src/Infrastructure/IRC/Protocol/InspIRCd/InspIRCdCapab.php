<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Message\IRCMessage;

use function in_array;
use function strlen;

/**
 * Parsed CAPAB data received from the remote InspIRCd server during link handshake.
 *
 * InspIRCd sends these CAPAB sub-commands between CAPAB START and CAPAB END:
 *   - CHANMODES  : list, param-set, simple and prefix modes available on the IRCd
 *   - MODSUPPORT : space-separated list of optional module names
 *   - USERMODES : user modes
 *   - EXTBANS   : extended ban types
 *   - CAPABILITIES : key=value pairs (MAXCHANNEL, CASEMAPPING, etc.)
 *
 * This VO is immutable; build it with fromCapabLines() and query it afterwards.
 */
final readonly class InspIRCdCapab
{
    /** @param array<string, int> $prefixModes prefix mode name → level (sorted ascending) */
    private function __construct(
        private array $prefixModes,
        private array $listModes,
        private array $paramSetModes,
        private array $simpleModes,
        private array $modules,
        private array $modSupport,
        private array $userModes,
        private array $extbans,
        private array $capabilities,
    ) {
    }

    /**
     * Build from the set of raw CAPAB lines received between CAPAB START and CAPAB END.
     *
     * @param list<string> $lines Raw IRC lines (e.g. "CAPAB CHANMODES :list:ban=b ...")
     */
    public static function fromCapabLines(array $lines): self
    {
        $prefixModes = [];
        $listModes = [];
        $paramSetModes = [];
        $simpleModes = [];
        $modules = [];
        $modSupport = [];
        $userModes = [];
        $extbans = [];
        $capabilities = [];

        foreach ($lines as $line) {
            $msg = IRCMessage::fromRawLine($line);

            if ('CAPAB' !== $msg->command) {
                continue;
            }

            $subCommand = $msg->params[0] ?? '';
            $payload = $msg->trailing ?? '';

            match (strtolower($subCommand)) {
                'chanmodes' => self::parseChanmodes($payload, $prefixModes, $listModes, $paramSetModes, $simpleModes),
                'modules' => $modules = self::parseSpaceList($payload),
                'modsupport' => $modSupport = self::parseSpaceList($payload),
                'usermodes' => $userModes = self::parseUserModes($payload),
                'extbans' => $extbans = self::parseExtbans($payload),
                'capabilities' => $capabilities = self::parseCapabilities($payload),
                default => null,
            };
        }

        asort($prefixModes);

        return new self(
            prefixModes: $prefixModes,
            listModes: $listModes,
            paramSetModes: $paramSetModes,
            simpleModes: $simpleModes,
            modules: $modules,
            modSupport: $modSupport,
            userModes: $userModes,
            extbans: $extbans,
            capabilities: $capabilities,
        );
    }

    public function hasPrefixMode(string $name): bool
    {
        return isset($this->prefixModes[strtolower($name)]);
    }

    /**
     * @return array<string, int> prefix mode name → level, sorted ascending
     */
    public function getPrefixModes(): array
    {
        return $this->prefixModes;
    }

    public function hasListMode(string $letter): bool
    {
        return in_array($letter, $this->listModes, true);
    }

    /** @return list<string> */
    public function getListModes(): array
    {
        return $this->listModes;
    }

    public function hasParamSetMode(string $letter): bool
    {
        return in_array($letter, $this->paramSetModes, true);
    }

    /** @return list<string> */
    public function getParamSetModes(): array
    {
        return $this->paramSetModes;
    }

    public function hasSimpleMode(string $letter): bool
    {
        return in_array($letter, $this->simpleModes, true);
    }

    /** @return list<string> */
    public function getSimpleModes(): array
    {
        return $this->simpleModes;
    }

    public function hasModule(string $moduleBaseName): bool
    {
        foreach ($this->modSupport as $mod) {
            $base = strtolower(explode('=', $mod, 2)[0]);
            if ($base === strtolower($moduleBaseName)) {
                return true;
            }
        }

        foreach ($this->modules as $mod) {
            $base = strtolower(explode('=', $mod, 2)[0]);
            if ($base === strtolower($moduleBaseName)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function getModules(): array
    {
        return $this->modules;
    }

    /** @return list<string> */
    public function getModSupport(): array
    {
        return $this->modSupport;
    }

    /** @return list<string> */
    public function getUserModes(): array
    {
        return $this->userModes;
    }

    /** @return list<string> */
    public function getExtbans(): array
    {
        return $this->extbans;
    }

    /** @return array<string, string> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function getCapability(string $key): ?string
    {
        return $this->capabilities[strtoupper($key)] ?? null;
    }

    /**
     * Parse CAPAB CHANMODES payload.
     *
     * Format: space-separated entries like:
     *   list:ban=b list:banexception=e
     *   param-set:flood=f param-set:limit=l param-set:key=k
     *   prefix:10000:voice=+v prefix:20000:halfop=%h prefix:30000:op=@o prefix:40000:admin=&a prefix:50000:founder=~q
     *   simple:allowinvite=A simple:blockcolor=c simple:c_registered=r
     *
     * @param array<string, int> $prefixModes
     * @param list<string>       $listModes
     * @param list<string>       $paramSetModes
     * @param list<string>       $simpleModes
     */
    private static function parseChanmodes(string $payload, array &$prefixModes, array &$listModes, array &$paramSetModes, array &$simpleModes): void
    {
        $parts = explode(' ', $payload);

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            $segments = explode(':', $part, 3);
            $category = $segments[0] ?? '';

            if ('prefix' === $category) {
                $level = (int) ($segments[1] ?? '0');
                $assignment = $segments[2] ?? '';
                $name = self::parsePrefixName($assignment);

                if ('' !== $name) {
                    $prefixModes[$name] = $level;
                }

                continue;
            }

            $assignment = $segments[1] ?? $segments[0] ?? '';
            $letter = self::parseModeLetter($assignment);

            if ('' === $letter) {
                continue;
            }

            match ($category) {
                'list' => $listModes[] = $letter,
                'param-set' => $paramSetModes[] = $letter,
                'simple' => $simpleModes[] = $letter,
                default => null,
            };
        }
    }

    /**
     * Parse the prefix assignment part like "voice=+v" → "voice".
     */
    private static function parsePrefixName(string $assignment): string
    {
        $eqPos = strpos($assignment, '=');
        if (false === $eqPos) {
            return '';
        }

        return strtolower(substr($assignment, 0, $eqPos));
    }

    /**
     * Parse mode letter from assignment like "ban=b" → "b", "c_registered=r" → "r", "key=k" → "k".
     */
    private static function parseModeLetter(string $assignment): string
    {
        $eqPos = strpos($assignment, '=');
        if (false === $eqPos) {
            return '';
        }

        $letter = substr($assignment, $eqPos + 1);

        if (1 === strlen($letter)) {
            return $letter;
        }

        if (2 === strlen($letter) && in_array($letter[0], ['+', '%', '@', '&', '~'], true)) {
            return $letter[1];
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function parseSpaceList(string $payload): array
    {
        return array_values(array_filter(explode(' ', $payload), static fn (string $s): bool => '' !== $s));
    }

    /**
     * Parse CAPAB USERMODES payload.
     *
     * Format: space-separated like "simple:bot=B simple:callerid=g param-set:snomask=s"
     *
     * @return list<string>
     */
    private static function parseUserModes(string $payload): array
    {
        $modes = [];
        $parts = explode(' ', $payload);

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            $segments = explode(':', $part, 2);
            $assignment = $segments[1] ?? $segments[0] ?? '';
            $letter = self::parseModeLetter($assignment);

            if ('' !== $letter) {
                $modes[] = $letter;
            }
        }

        return $modes;
    }

    /**
     * Parse CAPAB EXTBANS payload.
     *
     * Format: space-separated like "matching:fingerprint=z acting:noctcp=C"
     *
     * @return list<string>
     */
    private static function parseExtbans(string $payload): array
    {
        $bans = [];
        $parts = explode(' ', $payload);

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            $segments = explode(':', $part, 2);
            $assignment = $segments[1] ?? $segments[0] ?? '';
            $letter = self::parseModeLetter($assignment);

            if ('' !== $letter) {
                $bans[] = $letter;
            }
        }

        return $bans;
    }

    /**
     * Parse CAPAB CAPABILITIES payload.
     *
     * Format: space-separated key=value pairs like "CASEMAPPING=ascii MAXCHANNEL=60".
     *
     * @return array<string, string>
     */
    private static function parseCapabilities(string $payload): array
    {
        $caps = [];
        $parts = explode(' ', $payload);

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            $eqPos = strpos($part, '=');
            if (false === $eqPos) {
                continue;
            }

            $key = strtoupper(substr($part, 0, $eqPos));
            $value = substr($part, $eqPos + 1);
            $caps[$key] = $value;
        }

        return $caps;
    }
}
