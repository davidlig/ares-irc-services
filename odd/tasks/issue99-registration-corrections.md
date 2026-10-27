# Issue 99 registration decision corrections
Branch: `davidlig/fix-chanserv-correct-registration-decision-outpu`. Delivery: exception-ok (user explicitly chose one PR with size exception); forecast 2,000–3,000 authored lines; no push/PR/merge/deploy authorized.
Runner: focused PHPUnit without coverage; final project gates and exactly one full coverage run.

## Specs

### S1
## Summary

Follow-up bug fixes for the ChanServ registration-approval workflow implemented in #89. Correct STATUS presentation and privacy, decision memo authorship/content, CREG feedback and administrative-channel output, and administrative release of rejected channels. Also make HELP syntax notation accurate and consistent across all four bot services.

Apply every affected translation change together across all 14 supported locales: `ca`, `de`, `el`, `en`, `es`, `eu`, `fr`, `gl`, `it`, `nl`, `pl`, `pt`, `ro`, `tr`. This scope explicitly supersedes the Spanish-only preview restriction for these corrections, not for unrelated HELP changes.

## Reported behavior and reproduction

With channel registration in APPROVAL mode, submit a request for `#example`, reject it using an authorized IRCop, inspect the requester's MemoServ LIST/READ output and ChanServ STATUS, and try to release it using DROP before the restriction expires.

The maintainer reported these problems:

- ChanServ STATUS uses terse sentences instead of NickServ's header/aligned-fields/separator layout.
- The decision memo appears to come from the deciding IRCop rather than ChanServ, identifies that operator in its body, uses Spanish `Motivo`, and displays a remaining restriction in raw seconds.
- CREG REJECT's successful checkmark is yellow rather than green.
- HELP CREG and its invalid-usage response use `<motivo>` rather than `<razón>`. Some command signatures misleadingly mark variant-specific required arguments as optional.
- The administrative channel needs to distinguish `CREG ACCEPT` from `CREG REJECT` when `IRCOPS_DEBUG_CHANNEL` is configured.
- `DROP #example` refuses to release a rejected registration while a finite restriction remains active. The restriction check also runs before the FORCE branch.
- A duplicate pending registration reports only that the channel is already registered.

Evidence consists of maintainer-supplied live IRC output and targeted source/catalog inspection. No fresh daemon reproduction, migration, or functional test suite was executed while preparing this report.

## Required behavior



### S2
1. ChanServ STATUS presentation and privacy

Use NickServ's existing STATUS visual conventions for every ChanServ lifecycle state: localized header, aligned fields, equivalent translation-owned formatting, and a closing separator. Cover unregistered, pending, rejected, registered, suspended, forbidden, and pending-deletion states.

Spanish example for the requesting account:

```text
Estado del canal #example
  Estado          : Registro rechazado
  Razón           : canal duplicado
─────────────────────────────
```

Spanish example for an IRCop:

```text
Estado del canal #example
  Estado                  : Registro rechazado
  Razón                   : canal duplicado
  Rechazado por           : Reviewer el 27/10/2026 12:35 UTC
  Disponible a partir del : 28/10/2026 12:35 UTC
─────────────────────────────
```

- The requester may see rejection state and reason, but not the deciding operator, rejection timestamp, or availability timestamp.
- Any IRCop recognized by the existing centralized IRCop authorization policy, including Root, may see the reason, deciding operator/rejection timestamp, and finite availability timestamp. Do not require `REGISTRATION_REVIEW` to view those fields. Do not weaken the central IRCop policy into an unchecked raw oper flag.
- Other users receive only the generic state, without rejection details.
- Replace the long restriction sentence with the localized equivalent of `Available from:`; the exact Spanish label is `Disponible a partir del:`. Show an absolute UTC date, not a remaining-seconds count. Omit the availability field when there is no finite date; do not imply an indefinite restriction has expired or has zero remaining time.
- Pending requests must not acquire expiration fields or a countdown.
- This visibility change must not grant permission to execute CREG or other administrative mutations.



### S3
2. ChanServ-authored decision memos

Apply this to both ACCEPT and REJECT notifications, not just rejection:

- The persisted/displayed sender must be the configured ChanServ nickname. Both MemoServ LIST and READ must display that sender.
- Omit the deciding IRCop from the user-facing memo body. Keep operator identity in internal audit records.
- Use coherent localized reason terminology. Spanish must use `Razón`, not `Motivo`.
- For rejected requests with a finite restriction, append the absolute availability timestamp using `DD/MM/YYYY HH:mm UTC`. Format in UTC independently of the recipient's display timezone; use the decision event's existing `restrictedUntil` value, not a reconstructed seconds countdown.
- For an indefinite restriction, omit the availability/restriction text entirely. Do not display an indefinite warning or remaining seconds in that memo.

Expected Spanish rejection memo content:

```text
Mensaje #1 de ChanServ:
  Tu solicitud de registro de #example fue rechazada.
  Razón: canal duplicado
  Disponible a partir del: 28/10/2026 12:35 UTC
```

The ordinary MemoServ header/footer may keep their existing presentation. The UTC requirement above applies to the availability timestamp.

**Necessary sender-model correction:** notification creation currently stores the operator account ID as `Memo.senderNickId`; LIST and READ resolve that ID to the displayed author. A text-only change cannot fix this. Support an explicit service sender without creating a fake registered account or using a sentinel ID:

- Make `senderNickId` nullable and add an optional persisted `senderName` snapshot, with exactly one sender representation set.
- Update Domain construction/getters, XML mapping, the compatible schema migration, and LIST/READ sender projections. Preserve ordinary human memo construction, account lookup/fallback, and recipient cleanup.
- Resolve the configured ChanServ nickname through a MemoServ-owned output port and an adapter to the existing service nickname registry. Domain/Application must not depend on concrete protocol adapters.
- Snapshot the configured service name when creating the memo. Service-authored memos must not become attached to the deciding operator or disappear because that operator's account is dropped.
- Preserve existing stored memos unchanged; no historical authorship/body rewrite is authorized by this issue. Deploy the compatible migration before the new code.
- Preserve valid UTF-8 splitting and the 255-byte memo limit. Preserve notification-failure isolation: a memo failure must not roll back a committed acceptance/rejection. Missing service identity must not produce a fabricated sender.



### S4
3. CREG success feedback and IRCops debug channel

- Use green IRC color `03`, not yellow `07`, for the successful CREG REJECT checkmark, consistently across locales.
- When `IRCOPS_DEBUG_CHANNEL` is configured, announce each successfully completed decision exactly once, clearly displaying `CREG ACCEPT` or `CREG REJECT`, the acting IRCop, and target channel. Include the rejection reason using the existing administrative message conventions.
- Reuse the existing successful-command audit pipeline and ChanServ debug notifier. The outcome already carries `decision: accepted|rejected`; the channel presentation currently ignores it.
- Correct the display in `ChanServDebugNotifier`, not `OperServDebugNotifier`: routing selects the notifier using audit service `chanserv`.
- Preserve structured/PSR audit operation `CREG` and its decision metadata. Do not introduce duplicate decision events, subscribers, or announcements.
- Do not emit these announcements for PENDING, invalid input, authorization failures, or unsuccessful decisions. With the debug-channel configuration empty, emit no channel notice. Preserve other commands' audit output and existing channel protection/routing behavior.



### S5
4. Duplicate registration text

The Spanish duplicate-registration response must read:

```text
✗ El canal #example ya está registrado o en proceso de registro.
```

Use natural equivalents in the other locales. The existing active/pending duplicate outcome can be reused; do not change first-request confirmation, rejected restriction errors, or pending-deletion-specific behavior.



### S6
5. Accurate, consistent HELP syntax in all four services

Review NickServ, ChanServ, MemoServ, and OperServ against their actual declarative argument definitions. Use one notation: `<required>`, `[optional]`, and `{alternative|alternative}`, spelling each variant's own arguments accurately.

Required Spanish examples:

```text
CREG {PENDING [página]|ACCEPT <#canal>|REJECT <#canal> <razón>}
CREG REJECT <#canal> <razón>
ACCESS <#canal> {ADD <apodo> <nivel>|DEL <apodo>|LIST}
```

- Keep parent HELP, subcommand HELP, and contextual invalid-usage responses consistent.
- Replace affected CREG/STATUS/memo reason wording, descriptions, examples, and headings with the agreed localized terminology. Preserve placeholder names and internal identifiers.
- Do not make ADD/DEL required operands appear optional because LIST accepts fewer arguments. Preserve conditional grammars such as MemoServ IGNORE's optional channel form.
- Preserve accepted commands/aliases/arguments, validation precedence, business behavior, HELP permission filtering, and the established visual style: default-color syntax label and green-bold actionable syntax.
- Fix inconsistent signatures rather than rewriting already-correct or unrelated HELP prose. Do not introduce a generic cross-context formatting framework.



### S7
6. Administrative DROP must release rejected channels immediately

An IRCop authorized through the existing DROP permission must be able to release a rejected registration immediately, even while a finite restriction is active or the restriction is indefinite:

```text
/msg ChanServ DROP #example
/msg ChanServ DROP #example FORCE
```

- Plain DROP must not require FORCE for this explicit administrative release.
- FORCE is included and must retain its additional `DROP_FORCE` permission check. Both forms must reach the same rejected-registration release result when appropriately authorized.
- Ordinary requesters must not be able to bypass a rejection restriction. The relaxed STATUS visibility for any IRCop does not grant DROP mutation permissions.
- Reuse the existing hard-drop/rejected-registration release path. Supply trusted administrative release eligibility to the use case if required; do not infer authorization from an actor nickname merely being present.
- Remove the rejected registration and its current restriction so the channel name is available for a subsequent eligible registration, including one submitted by another account. Do not retain a separate decision/restriction record that continues blocking that name.
- Preserve existing successful release behavior for expired and indefinite rejections, current auditing, and applicable HISTORY policy.
- Leave pending-request behavior and ordinary registered-channel DROP/soft-deletion/FORCE behavior unchanged.
- Update affected HELP/docs and replace tests that currently treat authorized active-restriction refusal as expected behavior.

## Acceptance criteria and verification

- [ ] STATUS follows the NickServ layout for every lifecycle state.
- [ ] Routing-level privacy tests cover requester, unrelated user, recognized IRCop without registration-review permission, authorized reviewer, Root, and actors not recognized as IRCops.
- [ ] Both accepted and rejected notification memos display the configured ChanServ sender in LIST/READ and omit the deciding operator from their bodies.
- [ ] Finite availability uses absolute UTC timestamps; indefinite memo restriction text and indefinite availability fields are absent.
- [ ] Migration/persistence tests preserve historical rows and human memos, enforce exactly one sender representation, cover configured service names, and keep system memos independent of operator-account deletion.
- [ ] UTF-8/255-byte splitting and notification-failure isolation retain regression coverage.
- [ ] REJECT's successful checkmark is green, with all affected IRC controls/resets preserved.
- [ ] Configured debug-channel tests observe exactly one correctly distinguished ACCEPT/REJECT announcement; disabled configuration, PENDING, failed/unauthorized decisions produce none. Structured audit records and other commands remain unchanged.
- [ ] Duplicate active/pending registration messages use the broadened wording without changing other outcomes.
- [ ] All four services' affected parent/subcommand HELP and invalid-usage signatures match accepted variants and argument requirements.
- [ ] Authorized DROP and DROP FORCE release active-finite, expired, and indefinite rejected registrations; FORCE retains its additional permission. Assert success output/outcome, persisted removal, unregistered STATUS, and subsequent registration eligibility.
- [ ] Unauthorized release attempts preserve stored data and restrictions. Ordinary registered-channel DROP/FORCE and pending-request behavior retain focused regressions.
- [ ] All 14 locales are reviewed together for the affected corrections; preserve placeholders, IRC controls, YAML tags and intended whitespace/newlines. Do not add exhaustive catalog snapshots or cross-locale presentation assertions to the minimal English-only TranslationCatalogTest.
- [ ] Follow test-first discipline where runnable deterministic behavior tests apply. Add focused tests through public routes plus Domain/persistence boundaries as appropriate, and compare affected existing behavior against the baseline.
- [ ] Run independent verification for the persistence and privacy/authorization changes. Apply project formatting/normalization before final verification and any frozen review candidate.
- [ ] Pass PHP syntax checks, container/YAML linting, the configured architecture gate, PHPStan max, PHP-CS-Fixer, YAML formatting/checks, and the final 100% coverage gate with zero test issues. Run the full coverage suite exactly once at final verification.

## Related work and scope boundaries

This is a consolidated post-implementation correction to #89, not a request to reimplement the approval workflow or reopen that completed issue. Closed #85 and #87 provide prior reason-terminology and structural-validation work; reuse those conventions and cover the new/regressed cases rather than duplicating their mechanisms.

The scope includes the cross-service syntax review explicitly requested for this correction. It does not include unrelated commands/features, protocol redesign, new HISTORY policy, historical memo rewriting, automatic release when cooldown is zero, or changing review/registration mutation permissions beyond the explicitly described administrative DROP release.

Current workarounds such as consulting HELP or waiting for a finite cooldown do not correct these defects. This issue records agreed future work only; it does not implement changes or provide a branch-bound contribution grant. Follow CONTRIBUTING.md before development.


## Tasks
- T1 — S1–S3: service sender persistence, notifications and migration; delegated (parallel independent behavior); complete; commit `17239562`; Risk item 1.
- T2 — S1–S2, S4, S7: STATUS privacy, CREG debug and administrative DROP; delegated (parallel independent behavior); complete; commit `4d6ca6e1`; Risk items 2 and 3.
- T3 — S1–S7: all 14 locale presentation and all four services syntax correction; delegated (third independent catalogs/HELP unit); complete; commit `7909a271`.
- T4 — S1–S7: independent persistence/security verification and final normalized project gates; complete; tested source range `3e4d1502..7909a271`; exact coverage 24,039/24,039.

- T5 — S3: user-approved `senderServiceName` → `senderName`, getter and column rename plus explicit missing-sender guards; inline (mechanical understood change); complete; amended last commit (`7909a271..HEAD`), no new commit; Risk items 1 and 3.

## Log
L1 — User request: Implement GitHub issue #99 in /home/davidlig/desarrollo/ares-irc-services. Read the complete issue with gh issue view 99 --repo davidlig/ares-irc-services; full body and acceptance criteria authoritative. Full issue text is preserved verbatim in Specs above. User further requires test-first, independent persistence/security verification, all14locales, exactly one final full coverage gate, Spanish responses, no push/PR/merge/deploy/historical memo rewrite.
L2 — User explicitly instructed authorization via gh; grant6443446319 and both labels readback verified. User authorized gpt-6-luna substitution for unavailable prescribed gpt-6.1-luna workers.
L3 — Read-only mapping completed; baseline3e4d1502; tree clean. RDD off globally, no native review lifecycle will start. Dependencies absent; local locked composer install started with no scripts.
L4 — T1 RED8expectederrors -> GREEN322tests/2284assertions; T2 RED STATUS/DROP/debug -> GREEN122tests/604assertions plus baseline64tests/279assertions; T3 RED outdatedes syntax/duplicate -> GREEN8tests/4866assertions,84catalog lint. Parent spotcheck327tests/2717assertions passed after all units. Parent added explicit ServiceMemoSender DI alias and README deployment/behavior details. All14catalogs updated. Required BuildsChannelLifecycleRequest trait forwarding was a necessary T2 edit outside original delegated surface; parent inspected and accepted only that narrow forwarding change, and independent verifier will cover its shared callers (risk item3). No unrelated writer edits observed.
L5 — User delivery selection verbatim: “Una sola PR con excepción explícita de tamaño: conservar todo el alcance de #99 junto.” No publishing authorized.
L6 — Independent verifier scratch /tmp/issue99-verify.RqQajb: ChanServ/MemoServ 1954tests14067assertions; HELP49tests4818assertions; independent probes6tests193assertions. Baseline/candidate differential122tests345assertions each,76captured notices byte-identical/stdout stderr exit0. One B1 Greek SET EMAIL/ENTRYMSG optionaloperand signature corrected RED->GREEN; bounded recheck1test11assertions passed. Probes retained permanently in MemoServ RegistrationDecisionMemoRoutingTest and expanded ChanServ RegistrationDecisionRoutingTest.
L7 — Normalized PHP-CS-Fixer then composer yaml-fix; parsed YAML values/customtags unchanged. Initial final gates PHPsyntax28files/container/YAML130files/architecturezero/PHPStanmax/CSdryrun/YAMLcheck passed. Original full coverage command ./scripts/check-coverage.sh 100 --issues ran ONCE:6762tests63048assertions,2failures expected34migrationsactual35; exact24038/24042statements99.98%. Not a passing gate.
L8 — Corrected all4 migrationcount assertions to35; focused regression exposed rollback guard querying absent physical table during combined freshinstall dryrun. Guard now checks physical memos table existence before service-row query; preserves refusal if actual service rows exist. Independent bounded recheck migrationpair4tests242assertions passed including actualup/down and no-write dryrun. Removed unreachable restricted-output branch from authorized DROP, and replaced unreachable missing-sender exceptions with assertions of Memo sender invariant, no manufactured corrupt objects. Focused post-fix48tests571assertions passed. Assertion-enabled scoped coverage42tests310assertions passed; List24/24 and Read19/19 statements covered; Drop45/46 (unchanged getHelpParams line101 not exercised by focused selection; original fullsuite exercised it). Complete final proof remains pending.
L9 — Asked user explicitly whether to authorize a second and last full coverage run as exception to original exactly-once requirement; answer pending. No extra full run performed. Source implementation present, no commits/push/PR/merge/deploy/historical rewrite. T4 remains pending complete final proof; do not claim100% overall from scoped metrics.
L10 — User explicitly answered “SI” to authorize a second and last full coverage run as exception to the initial exactly-once requirement. Executed ./scripts/check-coverage.sh 100 --issues once more, exit0:6762tests63278assertions, zeroissues, exact24039/24039statements100%. No further fullrun or source changes afterward. Postcorrection PHPStanmax exit0/container/YAMLcheck/CSdryrun all passed.
L11 — Local coherent work units:17239562 memo sender persistence/migration+DI+all14memo notices+real LISTREAD tests/deploymentdocs;4d6ca6e1 STATUSprivacy/DROPeligibility/CREGdebug+all14statefeedback+persistedroutingtests/docs;7909a271 all4serviceHELPvariants/all14locales+targetedpresentationgrammarregressions. Larger-than400line units retain their coupled migration/consumer/regression and14locale surfaces; limit is advisory and user explicitly accepted singlePRsizeexception. Rollback boundaries are the respective commit file sets; runtime boundary tested through real inprocess public service dispatch+Doctrine SQLite, no live IRC/MariaDB/PostgreSQL daemon or production migration authorized. RDDoff: disabled/unmanaged for allunits; no native START or invented approval. Complete source bytes remained unchanged during commits and workingtree was clean after sourcecommits.
L12 — Independent baseline/verification scratch removed by parent after probes retained in permanenttests. Logs retained /tmp/ares99-final-coverage-second.log and /tmp/ares99-final.sQdl74. Deployment requires migrationVersion20261027000001 BEFORE new daemon; rollback refuses existingservice-authoredrows; historical memos unchanged. No push/PR/merge/deployment.
L13 — User correction verbatim: “senderServiceName creo que seria mejor senderName para poder utilizarlo de forma transversal (si lo requiere)”. User confirmed “si” to coordinated rename preserving current sender rule, then instructed verbatim “haz ammend no crees commit nuevo”. This later explicit naming correction overrides only S3 identifier, no other issue99 requirements or permissions changed. No GitHub issue edit/approval or new feature scope authorized. Rename pending local migration (not published/deployed by this session); preserve human-ID versus name snapshot XOR, lookup/cleanup/output and service identity port. Existing grant remains scoped to same behavior; no remote mutation needed.
L14 — Naming RED: updated existing regression expectations before production. Focused35tests78assertions failed5errors (new getter/argument/column absent), exit2. Amend target HEAD72dd4a35, parent7909a271; preserve commit count and parent.
L15 — User asked about assert(null !== $senderNickId); confirmed “si” to replacing both LIST/READ assertions with explicit LogicException checks. Subsequent questions asked whether it stops execution, is like return, and is enabled; read-only check verified local PHP CLI zend.assertions=-1/assert.exception=1, coverage script explicitly enables assertions. No deployed daemon settings inspected.
L16 — New real-Doctrine persisted-corruption regression (no constructor bypass/reflection/mock entity): missing both sender representations. RED with assertions disabled:4cases2errors2failures; READ incorrectly changed read_at before rejection. Implemented explicit LogicException in both handlers; READ resolves/validates sender before marking read, preserving the row on failure. GREEN assertions disabled:21tests87assertions including nick/channel LIST/READ guards, healthy projections and real public memo routes. No cast/sentinel or permission changes.
L17 — Rename-only candidate had completed allstaticgates and one fullcoverage run6762tests63278assertions24039/24039 while user authorized new guard changes. That run is superseded proof, NOT certification of newguardcandidate. Normalize/recheck guardcandidate and execute finalfullcoverage only once at final completion of this user-authorized refinement. Amend still pending; HEAD72dd4a35 and commitcount unchanged.
L18 — Independent finalguardverificationPASS:21tests87assertions with zend.assertions=-1 AND1; baselinecorruption repro2errors2failures including READhashmutation; healthypublicdifferential22tests122assertionseach42wiremessagesbyteidentical. Earlier snapshotrenameverificationSQL/targetupdown identical modulo sender_service_name→sender_name forSQLite/MySQL/MariaDB/PostgreSQLplatforms; onlySQLiteexecutiontested. Noauth/protocolchanges.
L19 — Finaluser-approved renamed+explicitguardcandidate normalized then ALLgatesPASS:PHPsyntax9changed/newfiles/container/YAML130/architecturezero/PHPStanmax/CScheck0of2142/YAMLcheck. Singlefinalfullcoverage invocation for this definitivecandidate:6766tests63295assertions,zeroissues,exact24041/24041statements100%,exit0. Rename-onlyfullrun6762tests beforelateruserguardrequest is historicalsupersededproof, not finalstate. No subsequentproduction/testchanges.
L20 — As user expressly instructed, amend originalHEAD72dd4a35 instead of addingcommit. Parent7909a271 retained; original fourcommits remain four. Finalamend includes coordinated senderName/getSenderName/sender_name mapping/migration/tests andexplicitguards. Pendingmigrationmodifiedlocally; if originalnamedcolumnmigration was applied externally, use separateforwardmigration underauthorizeddeployment, not silentmetadatareplay. No deployment,push,PR,remotehistoryrewrite orissueupdate. Remaininguserdecisionispublicationonly; issue100historicalmigrationrefactor untouched.
