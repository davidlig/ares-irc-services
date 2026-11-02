<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\AutomaticModeActions;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

use function array_filter;
use function array_intersect_key;
use function array_replace;
use function array_values;
use function implode;
use function str_contains;
use function str_replace;
use function str_split;

/** Applies defaults only at identity/preference transitions, never as a mode lock. */
final class AutomaticModeSessionService
{
    /** @var array<string, array<string, string>> Successfully added letters, by UID and semantic intention. */
    private array $owned = [];

    public function __construct(
        private readonly IdentifiedSessionTracker $sessions,
        private readonly RegisteredNickRepositoryInterface $nicks,
        private readonly NickNetworkUserLookup $users,
        private readonly AutomaticModeCatalog $catalog,
        private readonly AutomaticModeActions $actions,
    ) {}

    public function apply(string $uid, string $nickname): void
    {
        if ($this->actions->usesNativeProfiles() || $this->sessions->findNick($uid) !== $nickname) {
            return;
        }
        $nick = $this->nicks->findByNick($nickname);
        $user = $this->users->findByUid($uid);
        if (null === $nick || null === $user || !$user->isIdentified || $user->isOper) {
            return;
        }
        $profile = $nick->getAutomaticModes()->intentions;
        $available = $this->catalog->available();
        if (null === $available && [] !== $profile) {
            return;
        }
        $desired = [];
        foreach ($profile as $intention) {
            if (isset($available[$intention])) {
                $desired[$intention] = $available[$intention];
            }
        }
        $owned = $this->owned[$uid] ?? [];
        $currentModes = $user->modes;
        $remove = [];
        foreach ($owned as $intention => $letter) {
            if (($desired[$intention] ?? null) !== $letter) {
                $remove[$intention] = $letter;
            }
        }
        if ([] !== $remove) {
            if (!$this->actions->change($uid, '-' . implode('', $remove))) {
                return;
            }
            $currentModes = str_replace(array_values($remove), '', $currentModes);
            foreach ($remove as $intention => $letter) {
                unset($owned[$intention]);
            }
        }
        // Existing bits are not ours; only successful additions acquire ownership.
        $add = array_filter($desired, static fn (string $letter): bool => !str_contains($currentModes, $letter));
        if ([] !== $add && $this->actions->change($uid, '+' . implode('', $add))) {
            $owned = array_replace($owned, $add);
        }
        $this->owned[$uid] = array_intersect_key($owned, $desired);
    }

    public function remove(string $uid): void
    {
        $owned = $this->owned[$uid] ?? [];
        if ([] === $owned || $this->actions->change($uid, '-' . implode('', $owned))) {
            unset($this->owned[$uid]);
        }
    }

    /** Removal relinquishes ownership even if a user independently re-adds the bit. */
    public function observe(string $uid, string $delta): void
    {
        $adding = true;
        foreach (str_split($delta) as $letter) {
            if ('+' === $letter || '-' === $letter) {
                $adding = '+' === $letter;
                continue;
            }
            if (!$adding) {
                foreach ($this->owned[$uid] ?? [] as $intention => $ownedLetter) {
                    if ($ownedLetter === $letter) {
                        unset($this->owned[$uid][$intention]);
                    }
                }
            }
        }
    }

    /** Never reconstruct ownership from burst modes after a reconnect or UID reuse. */
    public function forget(?string $uid = null): void
    {
        if (null === $uid) {
            $this->owned = [];
        } else {
            unset($this->owned[$uid]);
        }
    }
}
