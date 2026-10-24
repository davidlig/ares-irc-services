# IRC HELP Style

Apply the self-contained visual grammar below to HELP in NickServ, ChanServ, MemoServ,
OperServ, and future services. This contract covers existing `HELP`, `HELP <command>`, and
`HELP <command> <subcommand>` output only; leave other service replies unchanged.

## Canonical visual grammar

| Element | Color / emphasis | Layout |
|---|---|---|
| Header | `07`, bold | `🤖 <title>`, reset, then a `14` separator; follow with one blank line before content |
| General service introduction | default | Describe the service's purpose clearly, in one or more lines, without naming commands |
| Section, options, restricted section | default foreground, bold | ` ■ <heading>` |
| Group / subgroup | `10`, not bold | `  ◆ <heading>` |
| Command / subcommand row | `10` marker; `03`, bold command | Four spaces before `›`, then padded command and default-color description |
| Navigation hint | `10` icon; `03`, bold actionable syntax | `ℹ` then default prose and `/msg <bot>`; only `HELP …` is green and bold |
| Syntax line | default label; `03`, bold syntax | `<label>: <syntax>` |
| Command help order | default | Purpose first; if options are listed, show options/navigation before syntax and examples; otherwise show syntax and examples after purpose |
| Expiration | `04` icon; `04`, underlined duration | `⚠`, default label/prose, red underlined full duration, default remaining prose |
| Separator / decoration | `14` | Non-essential separators and secondary metadata |
| HELP error / attention | `07` by default; unknown-command `✗` uses `04` | Existing denial/error/warning fragments outside expiration |
| Description / prose | default | Paragraphs, command descriptions, and ordinary values |

Cyan `10` is approved for complete group/subgroup headings as well as navigation markers, not
command names or ordinary paragraphs. Restricted section headings use the same uncolored bold
square style as public sections. Information must remain understandable without color or decoration.
Preserve existing command padding and header/separator width policies.

## General HELP spacing

Use this spacing in every bot service, including new services:

- After the localized service introduction, emit **two blank output rows** before
  the localized `help.general_header` command-list heading.
- After the last grouped or ungrouped command, emit **two blank output rows** before
  `help.general_footer` / its navigation hint.
- When NickServ or ChanServ has a configured inactivity-expiration notice, keep the order
  `help.general_footer` → one blank output row → expiration notice → closing separator. The
  notice is directly before the separator, with no blank row between them.

## Translation-owned controls and expiration

- Keep colors, emphasis, and marker glyphs in existing localized translation values, not PHP.
  Supply dynamic values through placeholders; do not add a style/helper class or private palette.
- Reset each fragment before default prose: colored/bold fragments use `\x03\x0F`; uncolored bold
  headings close with `\x02`. The bold control `\x02` is not mIRC color `02`.
- In HELP error and feedback messages, keep the transport prefix `/msg <bot>` in the default
  foreground and color/bold only the actionable `HELP` command and its arguments. If the command
  reference ends the message, do not append a terminal period after it.
- Use the shared `common.unknown_command` key for unknown-command errors in every service,
  including HELP. Color `✗` red (`04`) and `%command%` green (`03`), resetting each fragment
  before default prose. Keep `/msg %bot% ` in the default foreground and color only `HELP` green.
- Use the shared `common.unknown_help_option` key when HELP receives an unknown sub-option in
  any service. Use one consistent message: red `✗`, green option and parent command, and a
  default-color `/msg %bot% ` prefix followed by green `HELP <command>`; do not end with a period.
  Retain the period after the unknown-command sentence, but do not add punctuation after `HELP`.
  The Spanish value is `"\x0304✗\x03\x0F Comando desconocido \x0303%command%\x03\x0F. Usa /msg %bot% \x0303HELP\x03\x0F"`.
- NickServ and ChanServ show their existing expiration notice only when expiration is configured.
  Keep `⚠` red (`04`), then reset. Wrap the **complete localized duration**, including its unit,
  as `\x1F\x0304%days% days\x03\x1F` (translate `days`). Both color and underline end before
  the remaining prose. The NOTE label and all surrounding prose remain uncolored.
- Red `04` is restricted to NickServ `help.warning_marker` / `help.intro_expiration`, ChanServ
  `help.intro_expiration`, and the shared `common.unknown_command` marker (plus NickServ's
  `help.error_marker`).
  Do not introduce expiration behavior in MemoServ or OperServ.
- Color `06` is retired from HELP. Other forbidden structural colors are `00`, `01`, `02`, `08`,
  `09`, `11`, `12`, `13`, and `15`. These restrictions do not rewrite non-HELP legacy messages.
- Preserve natural wording and placeholders in all 14 locales:
  `ca de el en es eu fr gl it nl pl pt ro tr`.

## Help prose length and line breaks

- Keep each displayed explanatory HELP text line at or below **80 visible characters**; aim for 75–80 characters and wrap at word boundaries. Measure after excluding IRC formatting control bytes, but count visible spaces, punctuation, and placeholders as written.
- Break long explanations into separate displayed lines instead of leaving one dense paragraph. Preserve the intended wording and paragraph order; do not introduce line breaks that change command syntax or examples.
- Do not encode line breaks inside HELP translation values with the literal YAML escape `\n`. Use physical multiline YAML source instead, and verify the parsed translation contains the intended newline.
- Color-bearing multiline HELP strings use `!irc |-` literal blocks. The Bootstrap translation
  loader decodes only `\x02`, `\x03`, and `\x0F` (case-insensitively) in explicitly tagged
  strings before parameter interpolation. Write displayed newlines and indentation physically;
  do not use `\n`, `\x0A`, or `\x20` for layout. Preserve the existing decoded content and
  choose block chomping to retain its terminal-newline behavior. Plain multiline text uses
  untagged literal blocks; single-line color-bearing strings remain double-quoted. Do not
  globally decode escapes or alter the intentional CTCP literal-escape behavior.
- Render every command-example line with exactly two leading spaces. Translation values must not carry outer indentation; the service formatter owns this display margin. For command examples with multiple lines, keep the first single-line example in `example` and put each additional single-line example in a sibling `examples` YAML sequence. Mark the insertion point with `%command_prefix%` before command text; the service formatter replaces it with the uncolored `/msg <configured bot> ` prefix and applies the two-space margin. Keep IRC color escapes double-quoted. In MemoServ, the margin precedes the localized marker, which remains before the generated prefix. During staged locale rollouts, do not change legacy translations that still contain a literal prefix.
- Keep the 80-character display-line limit scoped to HELP; do not reflow unrelated
  service replies merely because they are long. The YAML multiline representation rule
  in `AGENTS.md` applies to every bot-service translation key.

## Maintenance checklist

Keep each service's formatter, presentation groups, and visibility policy inside that service.
Do not invent HELP levels or subcommands for visual presentation, change permissions/Root access,
or move IRC formatting into Domain/Application.

For every HELP change, verify:

- the same keys/placeholders across all locales, with default-color prose;
- exact header, section, group, command-row, and navigation roles, including resets;
- one blank line follows every HELP header before its content, including the general command list;
- general HELP has two blank output rows between its introduction and command heading, and between
  its final command and navigation hint;
- one blank line separates command and subcommand descriptions from their syntax line in every service;
- the general service introduction clearly describes its purpose without command references;
- full red/underlined expiration duration and uncolored surrounding prose in NickServ/ChanServ;
- formatter, command, permission-visibility, and translation-alignment regressions.
- displayed explanatory HELP lines stay within 80 visible characters, and intended line breaks survive YAML parsing without escaped `\n` sequences.

See [services.md](services.md) for command and service completion checklists.
