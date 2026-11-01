<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\IRCMessage;

use function array_pop;
use function count;
use function ctype_digit;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;

/** Network-wide advertised support, independently owned by this protocol. */
final class UnrealUdbAutomaticModeInventory
{
    // Native profile mode application does not maintain invisible-mode counters.
    private const array MODES = [
        'wallops' => 'w',
        'bot' => 'B',
        'no-ctcp' => 'T',
        'censor' => 'G',
        'hide-channels' => 'p',
        'registered-only' => 'R',
        'tls-only' => 'Z',
        'private-deaf' => 'D',
        'channel-deaf' => 'd',
    ];

    private ?string $directSid = null;

    private ?string $directName = null;

    private ?string $directModes = null;

    /** @var array<string, array{name: string, parent: string, modes: string|null}> */
    private array $servers = [];

    public function __construct(private readonly string $ownSid) {}

    public function reset(): void
    {
        $this->directSid = null;
        $this->directName = null;
        $this->directModes = null;
        $this->servers = [];
    }

    public function observe(IRCMessage $message): void
    {
        match ($message->command) {
            'PROTOCTL' => $this->protocol($message),
            'SERVER', 'SID' => $this->introduce($message),
            'SINFO' => $this->information($message),
            'SQUIT' => $this->split($message),
            'ERROR' => $this->reset(),
            default => null,
        };
    }

    /** @return array<string, string>|null */
    public function getAutomaticModes(): ?array
    {
        if (null === $this->directSid || null === $this->directModes) {
            return null;
        }
        $inventories = [$this->directModes];
        foreach ($this->servers as $server) {
            if (null === $server['modes']) {
                return null;
            }
            $inventories[] = $server['modes'];
        }
        $available = self::MODES;
        foreach ($inventories as $inventory) {
            foreach ($available as $semantic => $letter) {
                if (!str_contains($inventory, $letter)) {
                    unset($available[$semantic]);
                }
            }
        }

        return $available;
    }

    private function protocol(IRCMessage $message): void
    {
        if (null !== $message->prefix && (null === $this->directSid || $this->resolve($message->prefix) !== $this->directSid)) {
            return;
        }
        foreach ($message->params as $parameter) {
            if (str_starts_with($parameter, 'SID=')) {
                $sid = substr($parameter, 4);
                if (1 !== preg_match('/^[0-9][A-Z0-9]{2}$/D', $sid) || $sid === $this->ownSid || (null !== $this->directSid && $sid !== $this->directSid)) {
                    $this->directModes = null;

                    return;
                }
                $this->directSid = $sid;
            } elseif (str_starts_with($parameter, 'USERMODES=')) {
                $this->directModes = $this->parseModes(substr($parameter, 10));
            }
        }
    }

    private function introduce(IRCMessage $message): void
    {
        $name = $message->params[0] ?? '';
        if ('SERVER' === $message->command && null === $message->prefix) {
            if ('' !== $name) {
                $this->directName = strtolower($name);
            }

            return;
        }
        $parent = $this->resolve($message->prefix ?? '');
        if (null === $parent || '' === $name || count($message->params) < 2 || !ctype_digit($message->params[1])) {
            return;
        }
        $key = 'SID' === $message->command ? ($message->params[2] ?? '') : strtolower($name);
        if (('SID' === $message->command && 1 !== preg_match('/^[0-9][A-Z0-9]{2}$/D', $key)) || $key === $this->ownSid || $key === $this->directSid) {
            return;
        }
        if (!isset($this->servers[$key])) {
            $this->servers[$key] = ['name' => strtolower($name), 'parent' => $parent, 'modes' => null];
        }
    }

    private function information(IRCMessage $message): void
    {
        $key = $this->resolve($message->prefix ?? '');
        // The direct peer advertises itself through PROTOCTL, never SINFO.
        if (null === $key || !isset($this->servers[$key])) {
            return;
        }
        $this->servers[$key]['modes'] = count($message->params) >= 5 && null !== $message->trailing && ctype_digit($message->params[0]) && ctype_digit($message->params[1])
            ? $this->parseModes($message->params[2])
            : null;
    }

    private function split(IRCMessage $message): void
    {
        if (null === $this->resolve($message->prefix ?? '')) {
            return;
        }
        $key = $this->resolve($message->params[0] ?? '');
        if (null === $key) {
            return;
        }
        if ($key === $this->directSid) {
            $this->reset();

            return;
        }
        $pending = [$key];
        while (null !== ($removed = array_pop($pending))) {
            unset($this->servers[$removed]);
            foreach ($this->servers as $id => $server) {
                if ($server['parent'] === $removed) {
                    $pending[] = $id;
                }
            }
        }
    }

    private function resolve(string $identity): ?string
    {
        if (null !== $this->directSid && ($identity === $this->directSid || strtolower($identity) === $this->directName)) {
            return $this->directSid;
        }
        foreach ($this->servers as $key => $server) {
            if ($identity === $key || strtolower($identity) === $server['name']) {
                return $key;
            }
        }

        return null;
    }

    private function parseModes(string $modes): ?string
    {
        return 1 === preg_match('/^[A-Za-z]{1,64}$/D', $modes) ? $modes : null;
    }
}
