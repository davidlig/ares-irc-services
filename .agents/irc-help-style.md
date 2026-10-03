# IRC HELP Style

Use this guide for HELP presentation in NickServ, ChanServ, MemoServ, OperServ, and every future
service, command, or subcommand. HELP must remain readable on both light and dark IRC backgrounds.

## Canonical palette

| Role | mIRC color | Use |
|---|---:|---|
| Title / normal section | `07` | Service titles and ordinary section headings |
| Command / subcommand | `03` | Command names and actionable syntax tokens |
| Marker / navigation | `10` | Short symbols such as `›`, `ℹ`, and small markers only |
| Admin / error / danger | `07` | Restricted sections, denials, errors, and danger |
| Warning / attention | `07` | Warning labels and pending actions |
| Expiration warning icon | `04` | The `⚠` icon only in NickServ and ChanServ expiration notices |
| Separator / decoration | `14` | Non-essential separators and secondary metadata |
| Prose | default | Descriptions, paragraphs, and ordinary values |

Within HELP output, `07` replaces both previously used `04` and `06`, except for the single
expiration warning icon described above. This policy applies only to HELP presentation; leave
unrelated legacy colors in non-HELP service messages unchanged.

Do not use `10` for headings, command names, paragraphs, or other long text. Essential information
must remain understandable if color or decoration is not displayed.

## Translation-owned markup

- Keep HELP colors and marker glyphs in existing localized HELP translation values, not PHP.
- Do not add a color/style helper class or a private palette.
- PHP may supply dynamic values through translation placeholders; do not assemble marker glyphs or
  select colors in command code.
- Keep descriptions and prose in the client's default foreground. Reset each styled fragment so
  color and formatting cannot bleed into adjacent text.
- The bold control `\x02` is allowed; it is not mIRC color `02`.
- Keep natural wording translated in all 14 locales: `ca de el en es eu fr gl it nl pl pt ro tr`.

Color `04` is reserved only for the `⚠` icon in NickServ `help.warning_marker` and ChanServ
`help.intro_expiration`; the localized NOTE label and all following prose must remain uncolored.
Color `06` is retired from HELP. The following additional colors are not approved for new
structural HELP styling because contrast varies with client background: `00`, `01`, `02`, `08`,
`09`, `11`, `12`, `13`, and `15`. These restrictions apply only to HELP, not unrelated legacy
service messages.

## HELP hierarchy and maintenance

Preserve the supported levels `HELP`, `HELP <command>`, and `HELP <command> <subcommand>`; do not
invent levels for presentation. Keep permission visibility and command behavior unchanged.

For each HELP change, verify that:

- localized values define the same keys and placeholders in all 14 locales;
- palette regression tests inspect HELP translation values and reject forbidden structural colors;
- formatter, command, permission-visibility, and relevant translation-alignment tests pass;
- every styled fragment resets before default-color prose begins.

See [services.md](services.md) for the command and service completion checklists.
