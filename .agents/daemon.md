# Long-Running Daemon

Use for runtime registries, connection state, timers, schedulers, memory behavior, and reset logic.

## 1. Long-lived process assumption

Every retained reference may survive for the daemon lifetime.

Stateful runtime classes define:
- bounded key space;
- cleanup trigger;
- TTL/deadline;
- disconnect/reset behavior;
- reconnect behavior.

## 2. Registries

In-memory registries are valid when state is:
- required at runtime;
- bounded;
- cleaned explicitly;
- tested for pruning/reset.

Do not use process-global registries to bypass architectural boundaries.

Business state belongs behind business persistence.
Protocol session state belongs inside its protocol adapter.

## 3. Doctrine memory

Identity-map management is infrastructure responsibility.

Never inject EntityManager into Application to solve daemon memory growth.

## 4. Timers/event loop

Avoid:
- busy polling;
- duplicate timers;
- blocking sleeps;
- closures retaining dead sessions;
- timers surviving reset accidentally.

Deadline callbacks verify they still belong to the active session/round before mutating state.

## 5. Deadlines

Distinguish:
- inactivity timeout;
- absolute timeout.

Only relevant progress refreshes inactivity deadlines.

UnrealUdb reconciliation/transfer state machines define timeout semantics explicitly.

## 6. Logging/memory

Do not place large entities/object graphs/full frames in logging context.

Use bounded scalar identifiers.

## 7. Reset

Reset correctness is tested.

Expected:
- connect initializes;
- operation mutates;
- disconnect cancels timers/clears session state;
- reconnect starts without stale correlation IDs/transfers/authority state.

Persistent business/protocol projection state survives runtime reset unless owning semantics say
otherwise.
