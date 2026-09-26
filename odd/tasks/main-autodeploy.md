# Main autodeploy

## Objective, authorization and invariants

Implement the accepted GitHub Actions → dedicated isolated runner → protected host executor plan. Deploy the exact tested main SHA beneath `~/deploy/ares-irc-services` with `make down && make clean && make up`. Keep Docker, existing `.env.local`, MariaDB and data/log mounts under `~/docker/ares-irc-services`. No concurrent daemons, destructive automatic DB rollback, secret logging or remote mutation.

Local implementation and feature-branch work-unit commits authorized. Remote installation, GitHub registration/configuration, push/PR/merge and first production cutover are not authorized. Ask separately before those operations.

## Routing, tests and delivery

- Delegated direct per task: preparation requires 4+ files; every unit touches multiple nontrivial scripts/config/tests. Parent owns task state and commits.
- Test mode: test-first assumed pending user reply to the optional mode question; no configured project TDD setting found. Exact runner: `python3 -m unittest discover -s scripts/deploy/tests -v`; PHP focused runner if needed: `./vendor/bin/phpunit --no-coverage --display-all-issues`.
- Forecast: 900–1200 authored changed lines including infrastructure tests/docs. Strategy ask-on-risk, proposed feature-branch-chain pending user reply; no remote PR creation. Preserve coherent units rather than artificial 400-line splitting.
- RDD: on, source default, observed via `gentle-ai review mode status`. Assess each committed range, follow native preflight and candidate consent. First boundary `0a5c0d63cf63c5659531ba95c9c6f3a971b31755`.
- Final gates: shell/Python checks, deployment test suite, Docker Compose config if available, PHP container/YAML lint, PHPStan max, CS fixer, architecture gate and full coverage exactly once. No claim of live deployment verification.

## Tasks

- [x] **T1 — Preserve Docker persistence and fix environment synchronization.** Parameterized production bind paths with backward-compatible local defaults; added optional host-network production override across all Make targets; removed secret prefix logging; fix sync counter exit status and make it fixture-testable; test missing keys/idempotency/persistence. Route delegated (runtime/config/test writer). Rollback: only runtime config/sync changes and their tests.
- [ ] **T2 — Implement protected deployment transport and executor.** Bounded Unix request, archive/path/hash validation, lock, protected host configuration, releases, preflight, MariaDB/config backup, exact Make sequence, startup log readiness, manual recovery on failure. Tests cover negative paths without production operations. Route delegated (multiple scripts/tests). Rollback: standalone deployment scripts only; no production runtime changes until installation.
- [ ] **T3 — Wire Actions and document installation/recovery.** Main push only, both CI jobs required, production environment, isolated runner and systemd templates, submit command, checksum artifact, safe operator instructions. Route delegated (workflow/templates/docs writer); use frozen T2 transport contract. Rollback: remove deployment job and uninstalled infrastructure templates.
- [ ] **T4 — Integrate and verify.** Run applicable final gates, reconcile acceptance, record actual commit ranges and outstanding remote setup. Route delegated checks as useful; no live restart authorized.

## Acceptance and operational defaults

Runner `ares-deploy` is repository-scoped and has no Docker/DBus socket. Host executor alone controls Docker; Unix socket and configuration private. Request is archive path, SHA256, full commit SHA; fixed host executor validates all inputs. Compose project stable `ares-production`. Releases selected only after successful readiness. Backup failure aborts before down; migration/start failure stops new instance, preserves state and requires manual recovery. Existing config writable for entrypoint; APP_ENV overridden to prod. Poll up to 120s, require fresh EOS and four service introductions plus 10s stable container/no link loss; PID health alone is insufficient. First cutover stops old project from old checkout before activation. Persistent logs are never symlinked into release-local var/log cleaned by make clean.

## Progress, evidence and next step

- Branch `feat/main-autodeploy`, clean baseline above; no deployment writes at creation.
- Engram mirror pending: MCP writes fail because several active runtime sessions match and no authoritative session ID was supplied. Do not invent one.
- T1 observed RED then GREEN: 10 Python tests with 30 Make target/default-override subcases; sh -n and real Docker Compose config (local and production override) passed. No PHP/source behavior change; global gates reserved for final integration. Production runtime harness pending separate remote authorization.
- Delivery default if optional question unanswered: feature-branch-chain preparation only; no PRs published. TDD evidence is observed test-first, not an inferred configured setting.
- Next: commit T1, complete T2/T3, then final gates; no remote setup.
