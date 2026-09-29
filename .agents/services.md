# IRC Services

Use for NickServ, ChanServ, MemoServ, and OperServ behavior.

## 1. IRC commands are inbound adapters

Flow:

```text
PRIVMSG
  -> <Service>/Adapter/In/Irc/CommandRouter
  -> <Service>/Adapter/In/Irc/Command/<Command>
  -> typed Application input
  -> Application UseCase
  -> Domain + Port/Out
  -> semantic result
  -> IRC presenter
```

Command adapters own:
- command name/aliases;
- argument parsing;
- transport-level minimum args;
- HELP metadata;
- mapping IRC actor/session facts to typed input;
- translating semantic results into IRC output.

Application owns:
- operation validation;
- orchestration;
- business authorization orchestration;
- persistence decisions;
- semantic results/events.

Domain owns:
- invariants;
- state transitions;
- business policies.

## 2. No Context objects in Application

Never pass:
- `NickServContext`
- `ChanServContext`
- `MemoServContext`
- `OperServContext`

into Application use cases.

Bad:

```php
public function execute(NickServContext $context): void
```

Good:

```php
$result = $registerNick->handle(new RegisterNick(
    actor: $actor,
    nickname: $nickname,
    email: $email,
    password: $password,
));
```

Do not replace one large Context with another generic request bag.

## 3. Results and presentation

Application returns semantics.

Good:
- `RegistrationCompleted`
- `VerificationRequired`
- `PermissionDenied`

Presentation decides:
- translation key;
- placeholders;
- NOTICE vs PRIVMSG;
- HELP layout;
- IRC formatting/colors.

### NickServ canonical command pattern

Use `NickServ/Adapter/In/Irc/Command/RegisterCommand` as the migration reference:

```text
IRC command adapter
  -> immutable typed input
  -> <Action>Handler
  -> context-owned Port/Out capabilities
  -> semantic result
  -> IRC presentation
```

For security-sensitive workflows, time and randomness are explicit output ports. Repositories are
owned by NickServ Application, mail ports express the business notification rather than translated
subject/body strings, and cross-context events live under `Application/PublishedEvent`. Published
events may carry the persisted password hash when an integration requires it, but never plaintext
passwords or tokens.

During the bounded migration, a new IRC adapter may implement the legacy command registry contract;
that compatibility stops at the adapter. `NickServContext`, `SenderView`, translation keys, framework
services, and protocol actions must not enter the use case.

## 4. Translations

Every user-visible translation key exists in:

```text
ca de el en es eu fr gl it nl pl pt ro tr
```

Argument syntax:
- `<arg>` required;
- `[arg]` optional;
- `{A|B|C}` required choice.

HELP formatting belongs to IRC presentation, not Application. Keep color markup and marker glyphs in
the existing localized HELP translation values; do not add a helper class or hardcode markers or
color-selection calls in command PHP. Pass dynamic values through translation placeholders, keep
descriptions in the client's default foreground, and cover all 14 locale catalogs. Follow
[.agents/irc-help-style.md](irc-help-style.md) for the canonical palette and reset rules.

## 5. HELP presentation

Use `.agents/irc-help-style.md` for HELP colors, markers, emphasis, and resets. Keep each service's
formatter, group metadata, translations, and command-visibility policy inside that service. Do not
add a style/helper class: localized HELP values own color markup and marker glyphs, with dynamic
values supplied through placeholders. Keep descriptions in the client's default foreground and
add HELP regression coverage across all 14 locale catalogs.

## 6. Bots

Service bots are network adapters. A service bot owns its network identity and transport; it does
not own the service's command registry or business behavior.

Allowed responsibilities:
- introduce service;
- receive routed IRC messages;
- derive adapter input;
- send presented output.

Forbidden:
- repository workflows;
- account/channel policy;
- authorization implementation;
- password handling rules.

When adding or wiring a service bot:

1. Keep the bot in the service's inbound adapter/composition path. Route messages through the
   existing `ServiceCommandGateway` listener (`service_command_listener`) to that service's
   `CommandRouter`; do not call use cases or command handlers from the bot.
2. Provide its nickname and UID through `app.service_nickname_provider` and
   `app.service_uid_provider`. Keep protocol-specific registration and network actions in the
   relevant IRC protocol adapter.
3. Wire the bot and gateway in `config/services.yaml` (or the existing service configuration),
   reusing the registry's established command tag. Current examples are `nickserv.command`,
   `chanserv.command`, `memoserv.command`, and `operserv.command.new`; verify the actual tag rather
   than assuming all registries use the same spelling.
4. Add or update integration coverage for service identity/UID, message routing to the right
   command registry, and presented replies. Run the focused wiring and service-command tests.

## 7. Authorization

Separate:

```text
authentication facts
authorization policy
presentation of denial
```

Symfony voters are adapter mechanisms, not the source of business policy.

Business rules such as ownership/founder/suspended/forbidden/role permission belong in
Domain/Application policies.

Root bypass semantics are centralized.

## 8. Audit

Auditing is separate from authorization and command presentation.

After a sensitive operation:
- emit/send a semantic audit record;
- include actor/action/target/reason/safe metadata;
- exclude secrets;
- use output adapters for file logs or IRC debug channels.

## 9. Event-triggered behavior

Framework event subscribers belong in `Adapter/In/Event`.

Subscriber pattern:

```text
framework/published event
  -> adapter subscriber
  -> typed Application input
  -> Application handler/use case
```

Business logic does not remain in subscribers.

## 10. Drop cleanup

Every persistent reference to a nick/channel defines lifecycle behavior:

- CASCADE DELETE;
- SET NULL;
- TRANSFER;
- immutable historical snapshot.

Local atomic cleanup belongs inside the transaction boundary.
IRC/mail/protocol effects are post-commit external effects.

## 11. Protocol independence

Service code must not branch on:

```text
InspIRCd
UnrealStandalone
UnrealUdb
```

Service use cases express semantic network needs through ports.
The active Irc protocol adapter implements them.

## 12. Command review

A service command is correctly separated only if:

- parsing is outside Application;
- use case knows no translation key;
- Application sends no IRC output;
- adapter does not perform repository business workflow;
- authorization is not duplicated;
- secrets are not published;
- use case is testable without Symfony/IRC.

## 13. New command checklist

Before calling a service command complete:

1. Put IRC parsing and reply handling in `<Service>/Adapter/In/Irc/Command`; send typed input to an
   Application use case for the behavior. Define name, aliases, minimum arguments, syntax, order,
   short description, subcommand help, and permission metadata in the command adapter.
2. Register the command in the service's runtime command registry. Where the registry uses a tagged
   iterator, add its explicit tag in `config/services.yaml` (for example, `chanserv.command`). Check
   the compiled container's tag list and verify the registry resolves the command by its public name.
3. Add the command to its functional group in the service's presentation-only HELP group metadata.
   Keep group order and descriptions out of Application/Domain. If the command is restricted, use
   the same visibility policy for general HELP and direct `HELP <command>`; do not change execution
   authorization just to filter its help text.
4. Add every command, success, error, syntax, short/detail help, subcommand, and group-label key in
   all 14 languages: `ca`, `de`, `el`, `en`, `es`, `eu`, `fr`, `gl`, `it`, `nl`, `pl`, `pt`, `ro`,
   `tr`. Preserve each locale's natural wording and interpolation placeholders; do not copy one
   language's prose into another catalog.
5. Add focused tests for routing/registration, group placement/order, direct and general HELP
   visibility, detailed syntax/subcommands, and relevant permission/Root behavior. Extend
   `ServicesCommandHelpAlignmentTest` for new keys/locales and update the expected global command
   count when the registry grows.
6. Run the focused command, HELP, and container/registry integration tests, then follow the project
   quality gates. A command is incomplete if it executes but is absent from permission-filtered
   HELP or any supported locale.

For an IRCop command, also:

1. Define a service-owned permission constant and include it in that service's IRCop permission
   provider/catalog. Return the permission from `getRequiredPermission()`; use `isOperOnly()` for
   IRCop visibility and routing where the command contract requires it.
2. Use the centralized authorization policy/voter so identified Root users retain their explicit
   bypass and IRCops need the permission assigned to their role. Do not infer permission from user
   mode `+o` alone.
3. Verify the permission is present in OperServ's permission catalog, can be assigned with
   `ROLE PERMS <role> ADD <permission>`, and makes the command appear in the IRCop `HELP` section
   only for an authorized actor. Check denial for actors without that permission.
4. Record successful privileged operations through the command audit path with safe metadata.
