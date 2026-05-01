# Commands — Translations & i18n

Use this skill when adding translations for new commands or modifying existing ones.

---

## Mandatory Rule (CRITICAL)

**You MUST create translations for ALL available languages: `en` (English) and `es` (Spanish).**

Every key added to `.en.yaml` MUST also be added to `.es.yaml` with the corresponding Spanish translation, and vice versa. A task is not complete if any translation file is missing keys.

---

## File Locations

```
translations/
├── nickserv.en.yaml
├── nickserv.es.yaml
├── chanserv.en.yaml
├── chanserv.es.yaml
├── memoserv.en.yaml
├── memoserv.es.yaml
├── operserv.en.yaml
├── operserv.es.yaml
├── mail.en.yaml
├── mail.es.yaml
├── common.en.yaml
└── common.es.yaml
```

---

## Translation Key Structure

### Command Metadata Keys

Every command needs these metadata keys:

```yaml
mycommand:
  syntax: "MYCOMMAND <arg1> <arg2>"
  short: "Short description shown in HELP list."
  help: |
    Full help text that can span
    multiple lines.
```

### Command Message Keys

Responses sent to the user during execution:

```yaml
mycommand:
  done: "\x0303✓\x03 Operation completed for %arg1%."
  error_invalid: "\x0304✗\x03 Invalid argument: %arg1%"
  not_found: "\x0304✗\x03 %arg1% not found."
```

### Sub-command Keys (for SET, ACCESS, etc.)

```yaml
set:
  password:
    syntax: "SET PASSWORD <new_password>"
    short: "Change your password."
    help: |
      Changes your nickname password.
      Passwords must be at least 8 characters.
  email:
    syntax: "SET EMAIL <email>"
    short: "Change your email."
    help: "Updates the email address for your nickname."
```

---

## IRC Color Codes

| Code | Effect | Example |
|------|--------|---------|
| `\x02` | Bold on/off | `\x02bold\x02` |
| `\x0302` | Blue | `\x0302text\x03` |
| `\x0303` | Green | `\x0303success\x03` |
| `\x0304` | Red | `\x0304error\x03` |
| `\x0307` | Orange | `\x0307warning\x03` |
| `\x0310` | Cyan | `\x0310info\x03` |
| `\x0314` | Dark grey | `\x0314muted\x03` |
| `\x03` | Reset color | End of colored text |
| `\x0F` | Reset all formatting | End of bold + color |

### Examples

```yaml
register:
  success: "\x0303✓\x03 Nickname \x02%nickname%\x02 has been successfully registered."
  pending: "\x0307→\x03 A verification token has been sent to \x02%email%\x02."
  error: "\x0304✗\x03 Registration failed: %reason%"
```

---

## Permission Translations

For commands with IRCop permissions, add descriptions in BOTH language files:

```yaml
# translations/nickserv.en.yaml
permissions:
  nickserv.drop: "Drop a registered nickname"
  nickserv.info: "View extended information on any nickname"
  nickserv.sendpass: "Send password reset email"
```

```yaml
# translations/nickserv.es.yaml
permissions:
  nickserv.drop: "Eliminar un nickname registrado"
  nickserv.info: "Ver información extendida de cualquier nickname"
  nickserv.sendpass: "Enviar email de reseteo de contraseña"
```

These appear in `ROLE PERMS <role> LIST` output.

---

## Automatic Placeholders

### `%bot%`

Automatically injected with the service nickname:

```yaml
# No need to pass %bot% manually — it's auto-replaced
welcome: "Welcome to %bot%! Type /msg %bot% HELP for commands."
```

### Custom Placeholders

Pass via `$context->reply()`:

```php
$context->reply('mycommand.done', ['%nick%' => $nickname, '%count%' => $count]);
```

---

## Translation Helper Methods

Available on Context objects:

```php
// Translate with parameters
$message = $context->trans('key', ['%param%' => $value]);

// Translate in a specific language
$message = $context->transIn('key', ['%param%' => $value], 'es');

// Reply with translated string
$context->reply('key', ['%param%' => $value]);

// Reply raw (no translation)
$context->replyRaw('Unformatted message');
```

---

## Debug Action Translations

For IRCop debug actions:

```yaml
# translations/operserv.en.yaml
debug:
  action_message: "%operator% executes command %command% on %target%. Reason: %reason%"
  action_info: "Nick: %nick% | Host: %host% | IP: %ip%"
```

```yaml
# translations/operserv.es.yaml
debug:
  action_message: "%operator% ejecuta el comando %command% sobre %target%. Razón: %reason%"
  action_info: "Nick: %nick% | Host: %host% | IP: %ip%"
```

---

## Complete Example: English + Spanish

### English (`translations/nickserv.en.yaml`)

```yaml
drop:
  syntax: "DROP <nickname>"
  short: "Drop a registered nickname"
  help: |
    Drops a registered nickname from the database.
    This action cannot be undone.

    Syntax: DROP <nickname>
    Example: DROP OldAccount
  not_registered: "\x0304✗\x03 Nickname \x02%nick%\x02 is not registered."
  done: "\x0303✓\x03 Nickname \x02%nick%\x02 has been dropped."

permissions:
  nickserv.drop: "Drop a registered nickname"
```

### Spanish (`translations/nickserv.es.yaml`)

```yaml
drop:
  syntax: "DROP <nickname>"
  short: "Eliminar un nickname registrado"
  help: |
    Elimina un nickname registrado de la base de datos.
    Esta acción no se puede deshacer.

    Sintaxis: DROP <nickname>
    Ejemplo: DROP OldAccount
  not_registered: "\x0304✗\x03 El nickname \x02%nick%\x02 no está registrado."
  done: "\x0303✓\x03 El nickname \x02%nick%\x02 ha sido eliminado."

permissions:
  nickserv.drop: "Eliminar un nickname registrado"
```

---

## Common Mistakes

| Mistake | Symptom |
|---------|---------|
| Missing language file | Translation not found for one language |
| Wrong key format | `translation key not found` error |
| Using `%bot%` in `trans()` | Replace with actual value — `%bot%` only works in replies |
| Forgetting color reset `\x03` | Color bleeds into subsequent text |
| Permission missing one language | English shows but Spanish returns key name |

---

## Related Skills

- `.agents/services/commands.md` — Command structure overview
- `.agents/services/commands-permissions.md` — Permission synchronization with translations
- `.agents/services/debug-actions.md` — Debug action translations
