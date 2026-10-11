# UnrealIRCd UDB Adapter

Use for UnrealIRCd UDB module work.

## 1. Ownership

All UDB-specific protocol behavior belongs to:

```text
Irc/Adapter/Protocol/UnrealUdb/
```

UDB is not a bounded context.

Persisted UDB concepts remain protocol adapter concepts when their semantics are defined by the
UnrealIRCd UDB module.

Examples:
- UDB records;
- block state;
- authority approval/fingerprint;
- reconciliation inventory;
- staged transfer state;
- OCLG projection;
- takeover/authority state;
- UDB path identity/canonicalization.

## 2. Independence from UnrealStandalone

`UnrealUdb` is a sibling of `UnrealStandalone`, not an extension.

Forbidden:
- inheritance from `UnrealStandalone`;
- delegation/composition to `UnrealStandalone`;
- imports from `UnrealStandalone`;
- shared Unreal behavioral traits;
- common Unreal base classes solely for deduplication;
- shared protocol-specific persistence.

Duplication is acceptable when it protects independent evolution.

## 3. Internal structure

Recommended internal ownership:

```text
UnrealUdb/
├── Wire/
├── Model/
├── Session/
├── Reconciliation/
├── Transfer/
├── Synchronization/
├── Projection/
│   └── Oclg/
├── Persistence/
└── Takeover/
```

Create only justified directories.

`Model` is an adapter-internal protocol/runtime model, not Domain.

## 4. Coordinator design

A coordinator may coordinate multiple collaborators, but should not implement every state machine.

Extract responsibilities with independent:
- state;
- lifecycle;
- reset invariant;
- timeout rules;
- transitions;
- testability.

Typical separate responsibilities:
- peer/session authorization;
- HEL/barrier;
- reconciliation round;
- transfer tracking;
- ACK/ERR correlation;
- mutation queue;
- deadlines;
- OCLG projection;
- takeover coordination.

Do not split solely to reduce line count.

## 5. State machines

For each state machine define:
- states;
- accepted inputs per state;
- transition effects;
- reset/disconnect behavior;
- inactivity deadline;
- absolute deadline where applicable;
- duplicate/replay behavior;
- correlation IDs/round IDs;
- failure mapping.

Irrelevant repetitive peer traffic must not indefinitely extend an operation.

## 6. Service boundary

NickServ/ChanServ/MemoServ/OperServ never know:
- UDB block names;
- wire paths;
- record identity encoding;
- HEL/INF/RES/END/ACK/ERR;
- reconciliation rounds;
- OCLG representation.

Translate semantic service changes into protocol projection internally.

Conceptually:

```text
service semantic changes
  -> stable Irc/integration boundary
  -> UnrealUdb projection/model
  -> persistence/synchronization
  -> UDB wire
```

Record ownership: `C::<channel>::access::<nick>` is the UDB join-authorization allowlist
(key presence plus `+r`); its value is not a rank. Ares never writes or projects that
container: ChanServ ACCESS levels remain SQL-only and are enforced by ChanServ policy.

## 7. Persistence

Protocol persistence belongs beneath:

```text
UnrealUdb/Persistence/
```

Separate:
- protocol model;
- reconciliation inventory;
- transfer state;
- wire serialization;
- persisted projection;
- post-commit synchronization.

Doctrine repositories do not become Domain repositories merely because state is important.

## 8. Time and scheduling

Use deterministic clock/scheduler boundaries.

No:
- busy loops;
- blocking sleeps;
- unbounded timers;
- stale timers mutating reset sessions;
- deadline extension from irrelevant activity.

## 9. Security

Treat all peer-supplied paths, counts, identifiers, checksums, frame sequences and authority claims as
untrusted until validated.

Bound sizes/counts and reject invalid state transitions.

Do not log sensitive payloads wholesale.

## 10. Acceptance

A UDB change is complete only if:
- protocol compatibility is preserved;
- reset/disconnect semantics are explicit;
- success/duplicate/malformed/timeout paths are tested;
- no wire type escapes;
- no `UnrealStandalone` dependency is introduced;
- no UDB concept is promoted to business Domain without protocol-independent business meaning.

## 11. Native UDB 4 contract

Use the explicitly selected canonical native module as the wire authority; keep its source,
configuration, binaries and index read-only. Do not add legacy grammar or compatibility fallbacks.

- Require HEL 4, a 16-character lowercase hexadecimal epoch and OCL. Advertise OCLG only when
  implemented; retain the required ULine deployment policy. Never invent operclasses or promote
  a non-authority peer such as Zeus into local authority.
- Require all six INF blocks to carry timestamp zero and one common root SID, root epoch and
  watermark. Dataset origin identifies the source generation, not necessarily the direct transport peer.
- Preserve explicit `!n` NONE, `!u` unsigned number and `!s` canonical Base64 string values.
  Validate decoded schema, canonical path normalization, component/path/value limits and the complete
  IRC line budget. Never infer a string's type from a leading asterisk alone.
- Compute block-aware native binary logical-row digests with the native domains, partition/radix
  layout, ordering, counts and weights. Preserve empty-block semantics and reject canonical-key
  collisions. Hashing IRC text or physical SQL layout is not equivalent.
- Keep peer authorization and HEL confirmation ahead of reconciliation, MANIFEST, EXP and mutation
  handling. Malformed, foreign-origin or invalid-state input fails closed; it does not enable a fallback.

## 12. Snapshot and durable transfer

- Commit a local record change, manifest and common stream sequence/checkpoint atomically. Prepare
  that stream before burst reservation callbacks without prematurely sending HEL or declaring READY.
- Capture all six blocks and their root checkpoint from one coherent SQL view. Stream bounded batches
  into a private immutable spool; digest, inventory, counts and emitted rows describe those exact bytes.
- Allow one active outbound block stage. Release one credit only for its exact durable ACK, correlated
  to peer, round, block, transaction ID, digest and watermark. Duplicate, stale or foreign ACKs grant none.
  Bound and deduplicate queued requests; never flush unpublished future mutations to manufacture progress.
- Native REUSE needs no invented stage ACK. END, PING and PONG are not persistence acknowledgements.
- Inbound tail ordering uses the native unknown-destination PING response `402`, not token-PONG.
  Bind its nonce to the captured transport, authorized direct peer, round and local target. Reject foreign,
  stale and duplicate responses; cancel the binding on reset, expiry or round cancellation. This barrier
  proves transport ordering only and never grants durable ACK credit.

## 13. Origin and process lifecycle

Keep imported root SID/epoch/watermark as durable provenance through approval and reload. Starting a
local mutation stream records a separate dataset checkpoint; it must not erase imported provenance.

- Same-process reconnect preserves the process epoch and monotonic committed sequence while clearing
  transient peer identity, correlations, transfers and timers.
- A fresh process creates a fresh local epoch and performs full reconciliation. Do not restart at zero
  under an old epoch or imply a durable same-stream outbox that is not implemented.
- Persisted approval is not current peer/session authorization. Readiness still requires the current
  validated generation, ordering and reconciliation checks.

## 14. Portable SQL persistence

Retain SQL as the authoritative storage mechanism, including migration-managed typed kinds and staging.
Queue required DDL before typed backfill DML; migration dry-run never changes rows. Classify numeric
channel-access leaves using their schema while preserving string bytes, including secret values.

Bind staging Boolean/Binary values explicitly for inserts, updates, criteria and binary paging.
Decode fetched binary streams and Boolean representations through checked DBAL/platform conversions;
never truthiness-cast PostgreSQL `f` or stringify a fetched binary resource. Keep invalid SQL metadata
fail-closed. Unit/resource conversion checks do not constitute actual PostgreSQL runtime proof.

Python is permitted as an independent test oracle/harness, not as a production storage or protocol
dependency. Follow [testing.md](testing.md) for isolated verification and native evidence requirements.
