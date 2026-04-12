# Unified HELP Design for Services

All service bots (NickServ, ChanServ, MemoServ, and any future service) MUST follow a **unified HELP design** for consistent output across the network.

## Required Elements

### 1. Header

Coloured section header with icon and title, aligned with trailing dashes:

```php
// IRC colour codes
\x02\x0307 ℹ NickServ Commands \x0F\x0314────────────────────\x03
```

- `\x02` = bold
- `\x0307` = orange (command names, headers)
- `\x0314` = grey (secondary lines, separators)
- `\x0304` = red (errors)
- `\x0F` = reset

### 2. Command Lines (General Help)

List commands with brief description:

```
\x0307REGISTER\x03    Register a nickname
\x0307IDENTIFY\x03    Identify to your nickname
\x0307SET\x03         Set nickname options
```

### 3. Options Block (Subcommands)

When a command has sub-options:

```
\x0314Options:\x03
  \x0307FOUNDER\x03    Transfer channel foundership
  \x0307SUCCESSOR\x03  Set a channel successor
  \x0307MLOCK\x03      Lock channel modes
```

### 4. Syntax Line

Always show command syntax:

```
\x0307Syntax:\x03 \x02SET <option> <parameters>\x02
```

### 5. Footer

End with coloured separator:

```
\x0314─────────────────────────────\x03
```

## Syntax Convention

| Notation | Meaning | Example |
|----------|---------|---------|
| `<param>` | Required | `<nickname>` |
| `[param]` | Optional | `[password]` |
| `{A\|B\|C}` | Fixed alternatives | `{ADD\|DEL\|LIST}` |

## Translation Keys

Use Symfony Translator with domain-specific catalogues:

```php
// In HelpCommand
$translator->trans('help.header_title', ['%service%' => 'NickServ'], 'nickserv', $language);
$translator->trans('help.syntax_label', [], 'nickserv', $language);
$translator->trans('help.footer', [], 'nickserv', $language);
```

```yaml
# translations/messages.en.yaml (nickserv domain)
nickserv:
  help:
    header_title: "\x02\x0307 ℹ %service% Commands \x0F\x0314────────────────────\x03"
    general_header: "Available commands:"
    command_line: "\x0307%command%\x03    %description%"
    options_header: "\x0314Options:\x03"
    syntax_label: "\x0307Syntax:\x03"
    footer: "\x0314─────────────────────────────\x03"
```

## Reference Implementation

See `HelpCommand` in each service:

- `src/Application/NickServ/Command/Handler/HelpCommand.php`
- `src/Application/ChanServ/Command/Handler/HelpCommand.php`

Methods:
- `sendHeader()`: Coloured header line
- `showGeneralHelp()`: List all commands
- `showCommandHelp()`: Single command details
- `showSubCommandHelp()`: Subcommand options (for SET, ACCESS, etc.)

## Files Affected

- `src/Application/<ServiceName>/Command/Handler/HelpCommand.php`
- `src/Application/<ServiceName>/Command/HelpFormatterContextAdapter.php`
- `translations/messages.*.yaml`
- `src/Application/UnifiedHelpFormatter.php` (if shared)

## Checklist

- [ ] Header has icon, title, and trailing dashes
- [ ] Commands use `\x0307` (orange) for names
- [ ] Options block uses `\x0314` (grey) for header
- [ ] Syntax line present for each command
- [ ] Footer separator at end
- [ ] Translation keys follow `{service}.help.*` pattern
- [ ] Alternatives use `{A|B|C}` notation in help text

## IRCop Commands Section

When a user has IRCop permissions, show a separated section after normal commands.

### Format

1. **Blank line** after normal commands section
2. **Header**: `help.ircop_header` (e.g., "The following commands are available for IRCOPS:")
3. **Commands**: List only commands the user has permission for
4. **Footer**: Standard separator

### IRCop Header Translation

```yaml
help:
  ircop_header: "The following commands are available for \x0304\x02IRCOPS\x02\x03:"
```

The `\x0304\x02IRCOPS\x02\x03` renders as **red bold "IRCOPS"**.

### Implementation in HelpFormatterContextAdapter

Each service must implement the interface methods:

```php
public function getIrcopCommands(): iterable
{
    $sender = $this->context->sender;
    $account = $this->context->senderAccount;
    
    // Must be identified to have IRCop permissions
    if (null === $sender || null === $account) {
        return [];
    }
    
    $nickLower = strtolower($sender->nick);
    
    // Root users see all IRCop commands
    if ($this->rootRegistry->isRoot($nickLower)) {
        return $this->filterIrcopCommands(...);
    }
    
    // Must be IRCop and have permissions
    if (!$sender->isOper) {
        return [];
    }
    
    // Filter by actual permissions
    return $this->filterByPermission(...);
}

public function hasIrcopAccess(): bool
{
    $sender = $this->context->sender;
    $account = $this->context->senderAccount;
    
    if (null === $sender || null === $account) {
        return false;
    }
    
    $nickLower = strtolower($sender->nick);
    
    // Root users always have IRCop access
    if ($this->rootRegistry->isRoot($nickLower)) {
        return true;
    }
    
    // IRCops with at least one permission
    if ($sender->isOper) {
        // Check if user has any IRCop permission
        return $this->hasAnyPermission($account->getId(), $nickLower);
    }
    
    return false;
}
```

### Service-Specific Implementations

| Service | File | Commands |
|---------|------|----------|
| NickServ | HelpFormatterContextAdapter.php | USERIP |
| ChanServ | HelpFormatterContextAdapter.php | (none yet) |
| MemoServ | HelpFormatterContextAdapter.php | (none yet) |
| OperServ | OperServHelpFormatterContextAdapter.php | Uses different logic (root/oper) |

### Translations

Add to `translations/{service}.en.yaml` (English) AND `translations/{service}.es.yaml` (Spanish). Every key MUST exist in both files:

```yaml
# File: translations/nickserv.en.yaml
help:
  ircop_header: "The following commands are available for \x0304\x02IRCOPS\x02\x03:"

permissions:
  nickserv.userip: "View the real IP/Host of a user"
```