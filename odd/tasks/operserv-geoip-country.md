# OperServ GEOIP: country gate applied at connection time

Objective: let staff block a whole country at the network gate, the way the reference bots did with
their `paises` list, and the way UnrealIRCd can do natively with its `~country` extban.
Branch: `feat/operserv-geoip-country` (from `main`).
Delivery strategy: single PR, same shape as the other units.
Test runner: `php vendor/bin/phpunit` (focused, no coverage during development; coverage gate once at
the end via `./scripts/check-coverage.sh 100 --issues`).

## Verified facts (proved against the local UnrealIRCd 6.2.7)

- UnrealIRCd 6.2.7 ships GeoIP natively: `geoip_base` + `geoip_mmdb` (MaxMind) + `extbans/country`
  (`+b ~country:XX`, "Ban/exempt by country") + `geoip-tag` (IRCv3 `unrealircd.org/geoip`).
- `set { geoip-mmdb { file "…"; }; }` loads clean (configtest, 0 errors).
- The G-line of a country is applied at runtime with:
  - add: `GLINE ~country:NG :No permito en esta red` → *"G-Line added: '~country:NG'"*
  - list: `GLINE` → numeric 223
  - remove: `GLINE -~country:NG` (no space) → *"G-Line removed"*
- A G-line mask must be a single extban: `*!*@1.2.3.4` is rejected ("Cannot have '!' in masks"), and
  UnrealIRCd normalises the country extban to `~country:@NG` internally.
- G-lines cannot be declared in the 6.2.7 config file (the parser rejects every `gline` form), so the
  list is published at runtime; the IRCd's runtime state does not survive a restart, therefore the
  list is persisted in Ares and re-published when the link comes up.

## Specs

- S1 — `GEOIP ADD <CC> [reason]` registers a country code in the block list, persists it, and
  publishes `GLINE ~country:<CC> :<reason>` to the IRCd. `<CC>` is an ISO 3166-1 alpha-2 code,
  validated against the known country codes and uppercased.
- S2 — `GEOIP DEL <CC>` removes the country from the list and publishes `GLINE -~country:<CC>`.
- S3 — `GEOIP LIST` shows the blocked countries with their reason and registration date.
- S4 — The list is persisted in Ares (its own table) and re-published every time the IRC link is
  established, so a restart of the IRCd does not silently drop the policy.
- S5 — The command is oper-only behind a new `operserv.geoip` permission, in line with the other
  OperServ commands, and every string exists in the 14 locales.
- S6 — The IRCd side needs `geoip_base`, `geoip_mmdb`, `extbans/country` and
  `set { geoip-mmdb { file "…"; }; }`. That configuration is outside this repository and is applied
  separately.
- S7 — Gates: `tests/OperServ`, `tests/Shared` and `tests/Bootstrap` green, `lint:container`,
  `lint:yaml`, PHPStan max 0 errors, Deptrac 0 warnings / 0 errors, CS-Fixer clean,
  `check-coverage.sh 100` at 100.00%.

## Tasks

- [x] T1 — Protocol: a "full mask" G-line action (add/remove) in the service-actions port, wired in
      the UnrealIRCd adapters and a no-op for InspIRCd, which has no G-lines.
- [x] T2 — OperServ: the port method, the persistence (entity, repository port, Doctrine adapter, XML
      mapping, migration), the use case, the command with its permission, the re-publish on link
      establishment, the 14-locale strings and the tests.

## Log

- L1 — User's requirement, verbatim: "Debe funcionar como un proxy, es decir: Na más que conecte el
      usuario a la red, le pilla la IP, busca el país y compara con la lista" and "el bot escribe la
      línea en la config del ircd o en su defecto en una SQLite y ejecuta el comando /gzline … (Por
      ejemplo)".
- L2 — 2026-10-20: the country lookup stays in the IRCd (it already has the GeoIP engine and the
  `~country` extban), so Ares never needs a MaxMind reader in PHP. Ares only manages the list and
  publishes the G-lines, which is why the policy can act on the connection itself instead of on the
  later registration.
- L3 — 2026-10-20: the config-file route was discarded on evidence: the 6.2.7 parser rejects every
  `gline` statement in the config, so the list is published at runtime and persisted in Ares.
- L4 — 2026-10-20: delivered in two commits on `feat/operserv-geoip-country` (from `main`): the
  full-mask G-line action in the service-actions port, the UnrealIRCd adapters emitting
  `:sid GLINE ~country:ES :reason` and `:sid GLINE -~country:ES`, an explicit refusal in InspIRCd
  (no G-lines), the OperServ port method, the `geoip_countries` table with its mapping and migration,
  the `ManageGeoip` use case, the `GEOIP` command behind the new `operserv.geoip` permission, the
  re-publish subscriber on `NetworkSynchronizationCompletedEvent`, and the fourteen-locale string set.
  Gates: OperServ 858/4724, Irc 2284/18556, Shared 36223, Bootstrap 1132, PHPStan max 0, Deptrac
  0/0/0, CS-Fixer clean, coverage 100.00% (23469/23469).
- L5 — 2026-10-20: the two order-dependent counters were rebased to this branch's base (71 registered
  commands and 32 migrations, from `main` plus this feature) instead of the integration branch's
  values, because this branch is based on `main`. The IRCd-side configuration (S6) stays outside the
  repository: `geoip_base`, `geoip_mmdb`, `extbans/country` and
  `set { geoip-mmdb { file "…"; }; }`.
