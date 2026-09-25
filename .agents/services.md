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

HELP formatting belongs to IRC presentation, not Application.

## 5. Bots

Service bots are network adapters.

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

## 6. Authorization

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

## 7. Audit

Auditing is separate from authorization and command presentation.

After a sensitive operation:
- emit/send a semantic audit record;
- include actor/action/target/reason/safe metadata;
- exclude secrets;
- use output adapters for file logs or IRC debug channels.

## 8. Event-triggered behavior

Framework event subscribers belong in `Adapter/In/Event`.

Subscriber pattern:

```text
framework/published event
  -> adapter subscriber
  -> typed Application input
  -> Application handler/use case
```

Business logic does not remain in subscribers.

## 9. Drop cleanup

Every persistent reference to a nick/channel defines lifecycle behavior:

- CASCADE DELETE;
- SET NULL;
- TRANSFER;
- immutable historical snapshot.

Local atomic cleanup belongs inside the transaction boundary.
IRC/mail/protocol effects are post-commit external effects.

## 10. Protocol independence

Service code must not branch on:

```text
InspIRCd
UnrealStandalone
UnrealUdb
```

Service use cases express semantic network needs through ports.
The active Irc protocol adapter implements them.

## 11. Command review

A service command is correctly separated only if:

- parsing is outside Application;
- use case knows no translation key;
- Application sends no IRC output;
- adapter does not perform repository business workflow;
- authorization is not duplicated;
- secrets are not published;
- use case is testable without Symfony/IRC.

## 12. New command checklist

Before calling a service command complete:

1. Put IRC parsing and reply handling in `<Service>/Adapter/In/Irc/Command`; send typed input to an
   Application use case for the behavior. Define name, aliases, minimum arguments, syntax, order,
   short description, and `HELP` metadata in the command adapter.
2. Register the command in the service's runtime command registry. Where the registry uses a tagged
   iterator, add its explicit tag in `config/services.yaml` (for ChanServ, `chanserv.command`). Check
   the compiled container's tag list and verify the registry resolves the command by its public name.
3. Add every command, success, error, and `HELP` translation key in all 14 languages. Verify both
   general `HELP` and `HELP <command>` use the registered command and its translated metadata.
   Update the global command count in `ServicesCommandHelpAlignmentTest` when the registry grows.
4. Test parsing and use-case outcomes, then add a container/registry integration test that catches a
   missing service tag. Run the focused tests and the repository quality gates.

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
