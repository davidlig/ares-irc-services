# IRC HELP Style

Apply the visual grammar of `plans/command.txt` to HELP in NickServ, ChanServ, MemoServ,
OperServ, and future services. This contract covers existing `HELP`, `HELP <command>`, and
`HELP <command> <subcommand>` output only; leave other service replies unchanged.

## Canonical visual grammar

| Element | Color / emphasis | Layout |
|---|---|---|
| Header | `07`, bold | `🤖 <title>`, reset, then a `14` separator |
| Section, options, restricted section | default foreground, bold | ` ■ <heading>` |
| Group / subgroup | `10`, not bold | `  ◆ <heading>` |
| Command / subcommand row | `10` marker; `03`, bold command | Four spaces before `›`, then padded command and default-color description |
| Navigation hint | `10` icon; `03`, bold actionable syntax | `ℹ` then default prose and `/msg <bot>`; only `HELP …` is green and bold |
| Syntax line | default label; `03`, bold syntax | `<label>: <syntax>` |
| Expiration | `04` icon; `04`, underlined duration | `⚠`, default label/prose, red underlined full duration, default remaining prose |
| Separator / decoration | `14` | Non-essential separators and secondary metadata |
| HELP error / attention | `07` | Existing denial/error/warning fragments outside expiration |
| Description / prose | default | Paragraphs, command descriptions, and ordinary values |

Cyan `10` is approved for complete group/subgroup headings as well as navigation markers, not
command names or ordinary paragraphs. Restricted section headings use the same uncolored bold
square style as public sections. Information must remain understandable without color or decoration.
Preserve existing command padding and header/separator width policies.

## Translation-owned controls and expiration

- Keep colors, emphasis, and marker glyphs in existing localized translation values, not PHP.
  Supply dynamic values through placeholders; do not add a style/helper class or private palette.
- Reset each fragment before default prose: colored/bold fragments use `\x03\x0F`; uncolored bold
  headings close with `\x02`. The bold control `\x02` is not mIRC color `02`.
- NickServ and ChanServ show their existing expiration notice only when expiration is configured.
  Keep `⚠` red (`04`), then reset. Wrap the **complete localized duration**, including its unit,
  as `\x1F\x0304%days% days\x03\x1F` (translate `days`). Both color and underline end before
  the remaining prose. The NOTE label and all surrounding prose remain uncolored.
- Red `04` is restricted to NickServ `help.warning_marker` / `help.intro_expiration` and ChanServ
  `help.intro_expiration`. Do not introduce expiration behavior in MemoServ or OperServ.
- Color `06` is retired from HELP. Other forbidden structural colors are `00`, `01`, `02`, `08`,
  `09`, `11`, `12`, `13`, and `15`. These restrictions do not rewrite non-HELP legacy messages.
- Preserve natural wording and placeholders in all 14 locales:
  `ca de el en es eu fr gl it nl pl pt ro tr`.

## Maintenance checklist

Keep each service's formatter, presentation groups, and visibility policy inside that service.
Do not invent HELP levels or subcommands for visual presentation, change permissions/Root access,
or move IRC formatting into Domain/Application.

For every HELP change, verify:

- the same keys/placeholders across all locales, with default-color prose;
- exact header, section, group, command-row, and navigation roles, including resets;
- full red/underlined expiration duration and uncolored surrounding prose in NickServ/ChanServ;
- formatter, command, permission-visibility, and translation-alignment regressions.

See [services.md](services.md) for command and service completion checklists.
