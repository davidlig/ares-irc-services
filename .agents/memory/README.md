# Memory Management for Long-Running Daemons

Use this skill when working on the IRC services daemon, socket loops, or any long-running process.

## Why This Matters

IRC services run as daemons with infinite loops. PHP's memory model is designed for request-response cycles, not long processes. Without explicit management, memory grows indefinitely.

## Stateless Services

All services injected into the message loop MUST be stateless:

- **No per-message state**: Services should not store data between messages
- **DTOs are passed, not stored**: `SenderView`, `IRCMessage`, command DTOs are created, used, then discarded
- **If state is required**: Implement `Symfony\Contracts\Service\ResetInterface` and call `reset()` at end of cycle

### Exceptions (bounded state, do NOT reset)

These in-memory registries have bounded state with explicit cleanup:

- `IdentifiedSessionRegistry`: bounded by connected UIDs (QUIT/ServerDelinked cleanup)
- `ChannelRankSyncPendingRegistry`: bounded by channel count
- `PendingVerificationRegistry`: bounded by TTL + maintenance pruners
- `RegisterThrottleRegistry`: bounded by TTL + maintenance pruners
- `PendingEmailChangeRegistry`: bounded by TTL
- `RecoveryTokenRegistry`: bounded by TTL
- `IdentifyFailedAttemptRegistry`: bounded by TTL

These maintain essential runtime state and have dedicated cleanup mechanisms. **Do not call `reset()` on them** or data will be lost.

## Doctrine Identity Map (CRITICAL)

The `EntityManager` caches every entity it touches. In a daemon, this grows infinitely.

```php
// AFTER flush, clear the identity map
$em->flush();
$em->clear();  // REQUIRED

// At end of message processing cycle
$em->clear();
```

**Pattern**: After any persistence operation, call `$em->clear()` to detach all entities.

## Variable Cleanup

Immediately after processing, unset large objects:

```php
// Process message
$rawLine = $connection->readLine();
$message = $this->protocolHandler->parseRawLine($rawLine);
// ... handle message ...

// Cleanup
unset($rawLine, $message, $largePayloadArray);
```

## Garbage Collection

In custom socket loops, force GC periodically:

```php
public function run(): void {
    while ($this->running) {
        $this->processIncoming();
        $this->tick();

        // Every 100 cycles or so
        if (0 === $this->cycleCount % 100) {
            gc_collect_cycles();
        }
    }
}
```

## Logging Gotchas

Avoid keeping large objects in log context:

```php
// BAD: entire entity in context
$this->logger->info('Processed user', ['user' => $user]);

// GOOD: only identifiers
$this->logger->info('Processed user', ['uid' => $user->getUid(), 'nick' => $user->getNick()]);
```

## Files Affected

- `src/Infrastructure/IRC/Connection/SocketConnection.php`
- `src/UI/CLI/ConnectCommand.php`
- `src/Infrastructure/Messenger/DoctrineIdentityMapClearSubscriber.php`
- `src/Application/*/` registries (check cleanup mechanisms)
- Any handler that persists to Doctrine

## Checklist

- [ ] After `$em->flush()`, is `$em->clear()` called?
- [ ] Are large variables unset after processing?
- [ ] Does the main loop call `gc_collect_cycles()` periodically?
- [ ] Are logged context values minimal (IDs, not entities)?
- [ ] Is the service stateless? If not, does it implement `ResetInterface`?