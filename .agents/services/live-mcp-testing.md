# Live MCP Testing for IRC Services

Use this skill when validating implemented IRC service behavior against a running IRCd using the `irc` and `mariadb` MCP servers.

---

## Purpose

Live MCP checks are smoke/integration validation for behavior that only exists on a real IRC network. They complement, but never replace, PHPUnit and coverage verification.

Use live MCP validation after implementing or changing:

- NickServ, ChanServ, MemoServ, or OperServ commands
- IRCop/root-only behavior
- Channel founder/access behavior
- Registration, verification, or persistence flows that cross IRC and database boundaries

If the MCP servers are unavailable, report that live validation was skipped and continue with the mandatory local verification chain.

---

## Available MCPs

| MCP | Use For | Safety Rule |
|-----|---------|-------------|
| `irc` | Send service commands, join/part test channels, read live responses | Only use temporary test resources |
| `mariadb` | Inspect persisted state, lookup verification tokens, confirm cleanup | Read-only unless explicitly configured otherwise |

MariaDB MCP is configured read-only by default. Do not perform writes through MariaDB MCP unless the local configuration explicitly enables them and the user asked for it.

---

## Configuration

Local values live in `.opencode/mcp.env`, which is ignored by Git. Never commit or print secrets from this file.

Supported values:

```dotenv
OPENCODE_IRC_NICK=AresMCP
OPENCODE_IRC_ROOT_NICK=Ares
OPENCODE_IRC_ROOT_IDENTIFY_PASSWORD=change-me
OPENCODE_IRC_TEST_NICK_PREFIX=NickTest
OPENCODE_IRC_TEST_CHANNEL_PREFIX=#test-
```

`OPENCODE_IRC_NICK` is the normal MCP client nick. `OPENCODE_IRC_ROOT_NICK` must be used only when a live check really requires root, IRCop, or channel founder privileges.

Before sending any service command, resolve the real bot nickname from project configuration instead of assuming literal service names or duplicating them in `.opencode/mcp.env`:

| Service | Parameter | Environment Variable | Default in This Project |
|---------|-----------|----------------------|-------------------------|
| NickServ | `nickserv.nick` | `NICKSERV_NICK` | `NickServ` |
| ChanServ | `chanserv.nick` | `CHANSERV_NICK` | `ChanServ` |
| MemoServ | `memoserv.nick` | `MEMOSERV_NICK` | `MemoServ` |
| OperServ | `operserv.nick` | `OPERSERV_NICK` | `OperServ` |

Resolution order:

1. Check `config/services.yaml` for the service parameter and env var name.
2. Check `.env.local` first, then `.env`, for the corresponding env var value.
3. If the env var is absent and `config/services.yaml` defines a default parameter, use that default.
4. If the nick still cannot be resolved, stop and ask; do not guess.

Use `/msg <configured-service-nick> ...`, not hardcoded names. For example, root identification must target the configured NickServ bot nick resolved from `NICKSERV_NICK` / `nickserv.nick`:

```text
irc_privmsg(<configured NICKSERV_NICK>, "IDENTIFY <nickname> <password>")
```

---

## Hard Safety Rules

- Never run destructive commands against real nicks or real channels.
- Never use live `DROP`, `DEL`, `CLEAR`, `KILL`, `RAW`, `GLINE`, `AKICK`, role mutation, or access mutation against resources that were not created by the same validation flow.
- Always create temporary resources with unique suffixes, for example `NickTest<timestamp>` and `#test-<timestamp>`.
- Test channel operations only in channels created for the test, or in channels where `OPENCODE_IRC_ROOT_NICK` is known to be founder and the user explicitly allowed that channel.
- Prefer creating a new temporary channel over using an existing channel.
- Clean up temporary resources when the service exposes a safe cleanup command.
- Do not store or summarize passwords, tokens, or private connection details.

This is NON-NEGOTIABLE. A live test that risks real user/channel data is worse than no live test.

---

## Root Nick Workflow

Use the root nick only when needed.

1. Read the required local values from `.opencode/mcp.env` without printing them.
2. Change to the configured root nick with IRC MCP raw command if the current MCP nick is not already root.
3. Resolve the configured NickServ bot nick from `NICKSERV_NICK` / `nickserv.nick`.
4. Identify with the configured NickServ bot nick using `IDENTIFY <nickname> <password>` with `OPENCODE_IRC_ROOT_NICK` and `OPENCODE_IRC_ROOT_IDENTIFY_PASSWORD`.
5. Run only the minimal command needed for validation.
6. Return to a non-root MCP nick if you changed it. Prefer a unique nick based on `OPENCODE_IRC_NICK` to avoid colliding with the original randomized MCP nick.

Example tool sequence:

```text
irc_sendRaw("NICK", OPENCODE_IRC_ROOT_NICK, "")
irc_privmsg(<configured NICKSERV_NICK>, "IDENTIFY <OPENCODE_IRC_ROOT_NICK> <password>")
irc_privmsg(<configured OPERSERV_NICK>, "COMMAND UNDER TEST ...")
irc_getMessages()
irc_sendRaw("NICK", "<OPENCODE_IRC_NICK><suffix>", "")
```

Never include the real password in the transcript, final response, memory, commit message, or documentation.

---

## Temporary Nick Registration Flow

For NickServ registration or commands that require an identified account:

1. Generate a unique nick like `NickTest<timestamp>`.
2. Switch to that nick via IRC MCP.
3. Register it through NickServ.
4. If email verification is required, use MariaDB MCP read-only queries to locate the verification token for that temporary nick.
5. Complete verification through the normal service command using the token.
6. Execute the command under test.
7. Drop or deactivate the temporary nick only if the tested behavior or cleanup command is safe and applies to that same temporary nick.

Use DB token lookup only to bypass test email delivery. Do not update database rows directly for this flow.

---

## Temporary Channel Flow

For ChanServ channel commands:

1. Generate a unique channel like `#test-<timestamp>`.
2. Join/create the channel with the required test nick.
3. Register the channel with ChanServ if the behavior requires founder state.
4. Run the command only against that temporary channel.
5. Validate IRC replies with `irc_getMessages()` and persisted state with MariaDB MCP read-only queries when relevant.
6. Drop or clean the channel only if the cleanup command targets that same temporary channel.

Do not test channel mutations on public, production, or user-owned channels.

---

## Validation Checklist

For each live MCP validation, record in the final response:

- Whether IRC MCP was available
- Whether MariaDB MCP was available, if DB inspection was relevant
- Temporary nick/channel names used, but no secrets or verification tokens
- Commands validated at a behavioral level, not raw secret-bearing messages
- Any cleanup performed or intentionally skipped

If a live check cannot be run safely, say why and do not improvise against real resources.
