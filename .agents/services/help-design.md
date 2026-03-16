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