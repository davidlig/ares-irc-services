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
