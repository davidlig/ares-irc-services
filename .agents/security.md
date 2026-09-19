# Security

Use for passwords, account recovery, verification, authorization, IRCop permissions, audit, and
sensitive protocol input.

## 1. Passwords

Plaintext passwords are transient input.

Never:
- persist plaintext;
- log plaintext;
- publish plaintext on events;
- pass plaintext farther than required.

Hash/verify through Application output boundaries implemented by security adapters.
Domain entities store hashes, not hasher services.

## 2. Tokens/randomness

Verification/recovery tokens:
- use cryptographically secure generation;
- generate behind output boundaries;
- enforce expiration/one-time semantics where applicable;
- redact from logs;
- keep runtime storage bounded.

Tests use deterministic fakes.

## 3. Time-sensitive security

Lockouts, throttling, verification and recovery use explicit clock/current-time boundaries.

Do not scatter hidden `new DateTimeImmutable()` calls through security policy.

## 4. Authorization

Model separately:
- IRC oper status;
- identified account;
- root identity;
- role;
- permission;
- resource ownership/founder policy.

Symfony voters are adapters.
Business authorization rules belong in Domain/Application.

Root bypass semantics are centralized.

## 5. Audit

Audit records may contain:
- actor;
- operation;
- target;
- reason;
- timestamp;
- safe metadata/correlation ID.

They never contain:
- passwords;
- tokens;
- authentication secrets;
- unnecessary sensitive payloads.

File logging and IRC debug-channel output are separate adapters.

## 6. Logging

Log bounded scalar identifiers, not entire entities/requests/frames.

## 7. Protocol trust

Remote IRCd/UDB input is untrusted until validated.

Bound and validate:
- frame/message size;
- counts;
- identifiers;
- path grammar;
- replay/duplicates;
- transitions;
- timeouts.
