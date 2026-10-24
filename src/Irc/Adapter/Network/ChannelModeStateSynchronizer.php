<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;

use function count;
use function in_array;
use function str_replace;
use function str_split;

final readonly class ChannelModeStateSynchronizer
{
    /**
     * @param array<int, string> $modeParams
     */
    public function applyInitialParams(
        Channel $channel,
        string $modeStr,
        array $modeParams,
        ChannelModeSupportInterface $support,
    ): void {
        if ([] === $modeParams) {
            return;
        }

        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $paramIdx = 0;
        $adding = true;
        foreach (str_split($modeStr) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }
            if (!$adding || !in_array($char, $withParamOnSet, true)) {
                continue;
            }
            if ($paramIdx >= count($modeParams)) {
                break;
            }
            $channel->applyModeParam($char, $modeParams[$paramIdx]);
            ++$paramIdx;
        }
    }

    /**
     * Merge equal-TS parameter modes using the native ordering rules for common modes.
     * Unreal's limit mode keeps the higher limit and its key mode keeps the lexically higher key;
     * other conflicting parameters retain the already-established value.
     *
     * @param list<string> $modeParams
     */
    public function mergeEqualTimestampParams(
        Channel $channel,
        string $modeStr,
        array $modeParams,
        ChannelModeSupportInterface $support,
    ): void {
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $paramIdx = 0;
        $adding = true;
        foreach (str_split($modeStr) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }
            if (!$adding || !in_array($char, $withParamOnSet, true)) {
                continue;
            }
            if ($paramIdx >= count($modeParams)) {
                break;
            }
            $incoming = $modeParams[$paramIdx++];
            $existing = $channel->getModeParam($char);
            if (null === $existing || ('l' === $char && is_numeric($existing) && is_numeric($incoming) && (float) $incoming > (float) $existing)
                || ('k' === $char && strcmp($incoming, $existing) > 0)) {
                $channel->applyModeParam($char, $incoming);
            }
        }
    }

    /** Union two channel mode snapshots without applying one side's removals over the other. */
    public function mergeEqualTimestampModes(string $current, string $incoming): string
    {
        $letters = array_fill_keys(str_split(str_replace(['+', '-'], '', $current)), true);
        $adding = true;
        foreach (str_split($incoming) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }
            if ($adding) {
                $letters[$char] = true;
            } else {
                unset($letters[$char]);
            }
        }

        return [] === $letters ? '' : '+' . implode('', array_keys($letters));
    }

    /**
     * @param array<int, string>             $params
     * @param callable(string): ?NetworkUser $resolveUser
     */
    public function applyReceived(
        Channel $channel,
        string $modeStr,
        array $params,
        ChannelModeSupportInterface $support,
        callable $resolveUser,
    ): void {
        $listLetters = $support->getListModeLetters();
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $unsetWithParam = $support->getChannelSettingModesUnsetWithParam();
        $paramIdx = 0;
        $adding = true;

        foreach (str_split($modeStr) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }

            $role = ChannelMemberRole::fromModeLetter($char);
            if (null !== $role) {
                if ($paramIdx < count($params)) {
                    $user = $resolveUser($params[$paramIdx]);
                    ++$paramIdx;
                    if (null !== $user && '' !== $role->toModeLetter()) {
                        $channel->applyMemberPrefixChange($user->uid, $role->toModeLetter(), $adding);
                    }
                }
                continue;
            }

            if (in_array($char, $listLetters, true)) {
                if ($paramIdx < count($params)) {
                    $this->applyListMode($channel, $char, $params[$paramIdx], $adding);
                    ++$paramIdx;
                }
                continue;
            }

            if ($adding) {
                if (in_array($char, $withParamOnSet, true) && $paramIdx < count($params)) {
                    $channel->applyModeParam($char, $params[$paramIdx]);
                    ++$paramIdx;
                }
            } else {
                if (in_array($char, $unsetWithParam, true) && $paramIdx < count($params)) {
                    ++$paramIdx;
                }
                $channel->clearModeParam($char);
            }
        }

        $delta = $this->extractChannelModeDelta($modeStr, $listLetters);
        if ('' !== $delta) {
            $channel->updateModes($this->mergeModeString($channel->getModes(), $delta));
        }
    }

    /**
     * @param array<int, string> $params
     */
    public function applyOutgoing(
        Channel $channel,
        string $modeStr,
        array $params,
        ChannelModeSupportInterface $support,
    ): void {
        $listLetters = $support->getListModeLetters();
        $delta = $this->extractChannelModeDelta($modeStr, $listLetters);
        if ('' !== $delta) {
            $channel->updateModes($this->mergeModeString($channel->getModes(), $delta));
        }

        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $unsetWithParam = $support->getChannelSettingModesUnsetWithParam();
        $paramIdx = 0;
        $adding = true;
        foreach (str_split($modeStr) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }
            if (null !== ChannelMemberRole::fromModeLetter($char) || in_array($char, $listLetters, true)) {
                if ($paramIdx < count($params)) {
                    ++$paramIdx;
                }
                continue;
            }
            if ($adding) {
                if (in_array($char, $withParamOnSet, true) && $paramIdx < count($params)) {
                    $channel->applyModeParam($char, $params[$paramIdx]);
                    ++$paramIdx;
                }
            } else {
                if (in_array($char, $unsetWithParam, true) && $paramIdx < count($params)) {
                    ++$paramIdx;
                }
                $channel->clearModeParam($char);
            }
        }
    }

    /**
     * @param array<int, string> $params
     */
    public function applyListSnapshot(Channel $channel, string $modeChar, array $params): void
    {
        for ($i = 0; $i < count($params); $i += 3) {
            $mask = $params[$i] ?? '';
            if ('' === $mask) {
                break;
            }
            $this->applyListMode($channel, $modeChar, $mask, true);
        }
    }

    /**
     * @param list<string> $listLetters
     */
    private function extractChannelModeDelta(string $modeStr, array $listLetters): string
    {
        $delta = '';
        $adding = true;
        foreach (str_split($modeStr) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }
            if (null !== ChannelMemberRole::fromModeLetter($char) || in_array($char, $listLetters, true)) {
                continue;
            }
            $delta .= ($adding ? '+' : '-') . $char;
        }

        return $delta;
    }

    private function mergeModeString(string $current, string $delta): string
    {
        if ('' === $delta) {
            return $current;
        }

        $chars = array_fill_keys(str_split(str_replace(['+', '-'], '', $current)), true);
        $adding = true;
        foreach (str_split($delta) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }
            if ($adding) {
                $chars[$char] = true;
            } else {
                unset($chars[$char]);
            }
        }

        $result = implode('', array_keys($chars));

        return '' === $result ? '' : '+' . $result;
    }

    private function applyListMode(Channel $channel, string $modeChar, string $mask, bool $adding): void
    {
        if ('b' === $modeChar) {
            $adding ? $channel->addBan($mask) : $channel->removeBan($mask);
        } elseif ('e' === $modeChar) {
            $adding ? $channel->addExempt($mask) : $channel->removeExempt($mask);
        } elseif ('I' === $modeChar) {
            $adding ? $channel->addInviteException($mask) : $channel->removeInviteException($mask);
        }
    }
}
