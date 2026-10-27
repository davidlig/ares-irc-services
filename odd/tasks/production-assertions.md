# Production PHP assertion replacement — issue #101
Branch: `davidlig/fix-chanserv-correct-registration-decision-outpu`. Baseline: `7083854c`. Delivery: same local branch, no publishing; retain user instruction to amend rather than add commits.
Runner: focused PHPUnit assertions-off/on, independent verification, normalized final gates; full coverage once at final completion. Forecast: 300–600 authored changed lines, no artificial line cap.

## Specs

### S1
## Requested outcome

Replace the remaining native PHP `assert()` uses in production code with always-active validation or typed handoffs of already-validated facts. Correct behavior must not depend on `zend.assertions`.

The user explicitly requested changing the eleven production occurrences identified after the MemoServ follow-up in #99. This is independent of #99's ChanServ/memo scope and #100's migration simplification. Do not change PHPUnit assertions or unrelated test-only native assertions.

## Current behavior and evidence

The inspected local PHP CLI uses `zend.assertions=-1`, so native assertions are not evaluated. `scripts/check-coverage.sh` explicitly uses `zend.assertions=1`, meaning assertion-dependent behavior can differ between the ordinary CLI and the coverage runner. No deployed daemon configuration was inspected.

Inventory at the implementation handoff baseline (line numbers may move):

| Production file | Lines | Asserted fact |
|---|---|---|
| `src/Irc/Adapter/In/Event/AntifloodSubscriber.php` | 98 | Message prefix is present |
| `src/NickServ/Adapter/In/Irc/Command/IdentifyCommand.php` | 185 | Sender and successful result nickname are present |
| `src/NickServ/Adapter/In/Irc/NickServService.php` | 154 | Sender is present |
| `src/Irc/Adapter/Protocol/UnrealUdb/Model/UdbSchema.php` | 905 | Strict Base64 decoding succeeded |
| `src/Irc/Adapter/Protocol/UnrealUdb/Takeover/UdbWireTakeover.php` | 442, 563, 625, 645, 686 | Round IDs and decoded path components are present |
| `src/Irc/Adapter/Protocol/UnrealUdb/UnrealUdbRecordWriter.php` | 81 | Canonical path encoding succeeded |
| `src/Irc/Adapter/Protocol/UnrealUdb/Wire/UdbWireCodec.php` | 434 | HEL capabilities are nonempty |

This inventory is not evidence of eleven independent bugs. Several assertions follow real validations or typed public inputs. For example, takeover `accept()` rejects missing round IDs before dispatch, NickServ dispatch receives a non-null `SenderView`, and the successful identify result factory accepts a required nickname. Preserve those existing boundaries rather than inventing invalid internal states to justify new branches.

A read-only baseline run of the relevant Antiflood, NickServ, UDB schema/codec/takeover/writer tests passed: 439 tests, 7,776 assertions. This is existing regression evidence, not proof of the proposed implementation or live IRC/network behavior.

## Scope and expected behavior

1. Remove the eleven native production assertions from the seven listed files. Use ordinary checks for genuine runtime failure cases and precise typed arguments/results where earlier validation already proves the fact. Do not introduce another optional assertion mechanism or suppress static analysis.
2. Keep validation at its owning boundary. Do not add unreachable duplicate guards and then fabricate private state, mocked impossible entities, or reflection-only inputs to satisfy line coverage. A behavior-preserving typed refactor may document why a meaningful failing behavioral RED is not applicable, establish the baseline first, and retain real public negative-input regressions.
3. Preserve malformed peer-input handling: existing ignored/rejected outcomes, protocol error codes, logging policy, transaction rollback and persistent-state preservation. Do not turn a previously ignored malformed IRC/UDB frame into an uncaught exception that can terminate the daemon.
4. Where a genuine internal invariant requires an exception, make the check explicit and independent of PHP assertion configuration, choose the existing ownership/error convention, and verify failure propagation and absence of partial mutation. Do not add user-visible error wording or expose secrets without need.
5. Preserve healthy behavior: authentication and Root/IRCop permissions, antiflood filtering/exemptions/lockouts, identify/session side effects and event ordering, HEL capabilities and compatibility, round/transaction identity, checksum/path/schema limits, UDB staging/commit behavior, and service/protocol output.
6. Keep UnrealUdb isolated from UnrealStandalone and InspIRCd. No protocol redesign, new shared behavior layer, dependencies, configuration flags, global PHP setting changes, or broad result-model migration is requested.
7. Do not modify the fourteen test-only native `assert()` occurrences or PHPUnit's assertion APIs as part of this scope. Do not revisit the completed MemoServ guards or migration history.

## Acceptance criteria and verification

- [x] No native PHP assertions remain at the eleven listed production sites; no replacement depends on `zend.assertions` or a static-analysis suppression.
- [x] Inspect direct callers and prior validation before choosing each replacement. Document which sites become explicit checks and which use typed handoffs of already-validated facts.
- [x] Follow test-first discipline where a runnable requested behavioral change exists. For behavior-preserving removal of redundant assertions, document the RED exception honestly; do not manufacture a failure or claim a passing baseline test was RED.
- [x] Run focused tests with assertions disabled and enabled. Public valid and invalid cases must have the same intended behavior regardless of assertion settings, with zero PHPUnit issues.
- [x] Public-routing regressions retain unknown/missing sender, malformed prefix/target, identify success/failure, permission precedence, antiflood exemptions and no unintended side effects.
- [x] UDB public parser/writer/takeover regressions cover applicable missing or invalid round IDs, HEL capabilities, Base64/schema data and encoded paths; preserve error/ignore outcomes, round/transaction state, no writes after rejection, and supported normal binary/text records.
- [x] Compare affected existing public outputs, stderr/exit results, notifications and persisted state against the baseline in isolated scratch state. Preserve data hashes after rejected commands/frames where applicable. Keep committed tests independent of Git history or fixed local paths.
- [x] Obtain independent verification for protocol, persistence or authorization risk touched by the actual diff. Do not substitute a successful focused suite for that independent check.
- [x] Normalize before final verification. Pass PHP syntax checks, container/YAML lint, architecture, PHPStan max, PHP-CS-Fixer and YAML checks, and exact 100% production line coverage with zero PHPUnit failures, warnings, notices, skipped/incomplete/risky tests or deprecations. Run focused tests during development and the full coverage gate exactly once at final verification; report any failed or unavailable proof rather than retrying silently.

## Alternatives and workaround

Enabling native assertions globally would change runtime configuration and still leave validation optional across deployments; it is not the requested solution. Blindly replacing every assertion with an exception can change external failure semantics and create untestable dead branches. Prefer the smallest explicit checks and typed handoffs that preserve existing behavior.

## Contribution and delivery boundaries

Requested contributor: numeric user ID `561369`; source repository ID `1167938167`; exact source branch `davidlig/fix-chanserv-correct-registration-decision-outpu`; target `main`.

This issue itself is not a contribution grant. Obtain the separate unedited authorization comment and strictly ordered grant-pointer/approval labels required by `CONTRIBUTING.md` before development. The issue may accompany #99 on the same eventual authorized PR; do not create parallel PRs for the same contributor or infer publication permission.

No push, PR creation, merge, deployment, external daemon/IRCd access, production data access, credential discovery, remote execution, historical data rewriting, or dependency changes are authorized. Preserve existing local work. Use isolated local tests and synthetic data; live integration requires separate explicit authorization.


## Tasks
- T1 — S1: service-boundary invariants (NickServ + antiflood), inline; implemented, focused off/on passed; Risk item 2.
- T2 — S1: UnrealUdb typed validated handoffs and checks, delegated (independent parallel behavior unit with code/tests); implemented, focused off/on passed; Risk items 2 and 3.
- T3 — S1: baseline differential and independent verification, complete; Risk items 2 and 3 verified.
- T4 — S1: complete; normalization/static/architecture gates PASS; 6771 tests, 63339 assertions, exact 24043/24043 coverage; delivered with T1–T3 by amend of existing HEAD (supersedes 7083854c), no additional commit.

## Log
L1 — User request verbatim: “cambialos”, after inventory of eleven remaining production assert() sites. User confirmed “si” to “¿Creo la incidencia y tramito su autorización con gh?”. Scope excludes test-only asserts. Prior explicit commit instruction retained: “haz ammend no crees commit nuevo”.
L2 — Created issue101 and verified fullbody; valid grantcomment6449455722 author561369/repo1167938167/exactcurrentbranch/main. Comment17:48:32Z, pointer17:48:37Z, approved17:48:42Z on2026-10-27, actors561369; open enhancement+onepointer+approved. No PR created; mostrecent authorPR97 opened11:05:45Z precedes both currentgrants. RDDoff.
L3 — Read-only CodeGraph mapping+targeted sources found many prevalidated invariants; no blanket throwing on malformed peerinput. Baseline focused439tests7776assertionsPASS11.6s. For purely behavior-preserving typedhandoffs meaningful behavioralRED unavailable: document exception, run realistic publicnegative regressions/baselineequivalence rather than invent invalidprivateobjects. Any genuine newlyreachable check requires observed RED beforefix.
L4 — T1 typedhandoffs: Antiflood validates prefix at publicboundary and passes string toprivatecheck; NickServ dispatch passes existingnon-nullSenderView toprivateexecutor; Identify passes validatedsender tosuccess presenter and obtains requiredname through IdentifyNickResult::requireNickname nativeLogicExceptionguard. NewaccessorRED2tests1error1failure (methodabsent), GREEN servicefocused47tests239assertions withassertsoff ANDon. Existing malformed/missingprefix andunknownsender/target,identify/permissionregressionspreserved. No fake null-successresults or silentcasts.
L5 — T2 removed8UDBasserts using typedroundIDs, typeddecodedpathresult, schema validation returns decodedspamfilter pattern toaggregatecompiler, HELemptycapexplicitreject and recordwritercombinedvalidation. Self-review corrected newunencodablepath warning toEXACToldmessage/context and removed redundantdeaddecodeguard. RealNUL-path/no-sideeffect and emptyHEL/HELACK negativecases pluspathcountregression added. Focused428tests8987assertions withassertsoff/on eachPASS; scopedPHPStanmaxPASS; focusedcoveragenochangedlinesuncovered. No syntheticprivateinvalidstateREDclaimed forbehaviorpreservinghandoffs.
L6 — All11originalproductionasserts absent; test-onlyasserts untouched. AuthoritativeS1targetincludesnecessaryexistingUdbPathCodec helper andresultaccessor toavoidoptionaltypeinvariants; no newframework/config/permissions/translations. Independentverification and finalnormalizedgates pending. Scratchreportedbywriter /tmp/tmp.BduVImVJ1y, parentwillclean afteruse.
L7 — Independent verifier PASS entireS1:428UDBtests8987assertions+47service tests239assertions perassertionsoff/on. Instrumentedbaseline/candidate155tests7103assertionspersnapshot/setting,6202capturedobservablesbyteidenticalallfourruns,stderr empty/exits0.355caseSchema/Path/Wirecorpusidentical; NickServaudit13tests77assertions+twoauditeventsidentical. RealSQLitepeerrejectionprobe1test23assertionspersettingpreservedliveSQLhash anddiscardedinvalidstaging. Nativeproductionassert11→0, test-only14→14unchanged. No block/finding; isolatedinprocessonly, notliveIRCd.
L8 — Retained independentpeerrejectionprobe aspublic UdbWireTakeoverTest::rejectedPeerFramesLeaveExistingDataUntouched, excluding temporaryobserver. Parentobserved1test23assertionsPASS. Baselinescratch /tmp/issue101-verify.EkX59X pluswritercoverage/tmp/tmp.BduVImVJ1y retained untilfinalproof thenparentcleanup. Source/testnormalization complete PHP-CS-Fixer thenyaml-fix, nofunctionalchangesafterverdict; onlyretainedtestadded. Finalgates/fullcoverage pending (nonefullrunforissue101yet).

L9 — Resumed session: workspace authoritative, prior transcript read-only. Recovered final logs /tmp/ares101-final.v9lSxT: syntax/container/YAML/PHPStan/Deptrac (0 violations, 0 uncovered)/formatter checks passed; full suite already ran once, 6770 tests63338assertionsPASS. Exact Clover metrics24042/24043, uncovered UdbSchema.php:306 returnfalse after validate rejection. Script rounded to100.00% and exitedsuccess; this is NOT exact100% acceptance. No silentfullrerun, noamend, no sourcechanges. Needs user decision: authorize focused public regression coverage correction followed by a second full coverage gate, or preserve pendingstate. RDD remains globallyoff. Scratch retained for diagnosis.

L10 — User answered verbatim “si” to authorizing a focused coverage correction and second final full coverage run before amend. Add public malformed nickname aggregate regression; production behavior remains unchanged, so no meaningful behavioral RED applies (coverage gap, not behavior fix). Second full run authorized once; no push/deployment or unrelated coverage-script changes.

L11 — Added public UdbSchemaTest::aggregateRejectsInvalidNicknameRecords (empty pass rejection) without production edits. Focused schema suite with assertionsoff/on:262tests479assertionsPASS each; parent spotcheck1test1assertionPASS. Final bounded worker final_gates (skill_resolution paths-injected) observed all15PHP syntaxchecks, containerlint,130YAMLlint, PHPStanmax, Deptrac zeroissues, convergentPHP-CS-Fixer thenyaml-fix/yaml-check allPASS; no source normalizationchanges. Authorizedsecondfullrun6771tests63339assertionsPASS, zeroPHPUnitissues; directClover24043/24043 exact100%. Risk items2/3; existing independentverificationL7 stands, onlypublictestadded. RDD globallyoff disabled/unmanaged; no reviewstarted. Amend-only delivery authorized, no push/PR/deployment. RuntimeharnessN/A: isolatedPHP/SQLiteproof, no liveIRCd authorized. Rollbackboundary issue101 source/test paths in diff versus7083854c; preserve earlierMemoServ work.
