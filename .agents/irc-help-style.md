# IRC HELP Style

Apply the self-contained visual grammar below to HELP in NickServ, ChanServ, MemoServ,
OperServ, and future services. This visual grammar covers `HELP`, `HELP <command>`, and
`HELP <command> <subcommand>`. Ordinary replies use semantic roles in the same presentation engine.

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

## Style-owned controls and expiration

- Keep translations free of colors, decorative markers and presentation controls. The shared
  renderer applies the selected style to semantic components and fragments. Commands never own
  private palettes. Explicitly unmigrated locales retain their legacy path until staged approval.
- The renderer resets each styled fragment with `\x03\x0F` before default prose and reapplies
  the enclosing appearance when needed. Legacy uncolored bold headings may close with `\x02`;
  that control is not mIRC color `02`. Do not embed either reset scheme in migrated translations.
- In HELP error and feedback messages, keep the transport prefix `/msg <bot>` in the default
  foreground and color/bold only the actionable `HELP` command and its arguments. If the command
  reference ends the message, do not append a terminal period after it.
- Use the shared `common.presentation.unknown_command` key for unknown-command errors in every service,
  including HELP. Color `✗` red (`04`) and `%command%` green (`03`), resetting each fragment
  before default prose. Keep `/msg %bot% ` in the default foreground and color only `HELP` green.
- Use the shared `common.presentation.unknown_help_option` key when HELP receives an unknown sub-option in
  any service. Use one consistent message: red `✗`, green option and parent command, and a
  default-color `/msg %bot% ` prefix followed by green `HELP <command>`; do not end with a period.
  Retain the period after the unknown-command sentence, but do not add punctuation after `HELP`.
  Localized prose uses placeholders for command fragments; the renderer supplies the visual roles.
- NickServ and ChanServ show their existing expiration notice only when expiration is configured.
  Keep `⚠` red (`04`), then reset. Wrap the **complete localized duration**, including its unit,
  with an Expiration fragment (translate the entire duration, including `days`). Both color and
  underline end before the remaining prose. ExpirationNotice owns the red marker; the NOTE label
  and all surrounding prose remain uncolored.
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
- Multiline migrated text uses plain literal YAML blocks and physical newlines. Keep the legacy
  `!irc` loader only for explicitly unmigrated values; do not expand its decoding scope or alter
  intentional CTCP escapes. Match existing terminal-newline behavior.
- Example content uses `example`/`examples` and `%command_prefix%` as before. The Example component
  owns the two-space margin; the transport prefix remains uncolored and command syntax is a Command
  fragment. Localized example labels stay in their original order. No indentation or markers in
  migrated example values; preserve the legacy path for locales awaiting validation.
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

## Shared component ownership and extension

Use `IrcRenderer::component()` with the component enum and localized text. For example:

```php
$renderer->component(Component::Group, $context->trans('help.groups.registration'));
$renderer->component(Component::Example, '/msg %bot% %command%', [
    '%bot%' => $configuredBot,
    '%command%' => new Fragment('HELP SET', Component::Command),
]);
```

`PresentationStyle` owns appearance, `StyleProvider` owns selection, and the renderer owns resets.
The default style follows the table above; alternate styles reuse the same content and components.
All output lines end their active formatting. Fragment boundaries restore the enclosing appearance.
Do not add configuration sources until separately authorized. Never centralize service visibility,
permission checks, grouping or expiration policy in this library.

For ordinary data output, use TableHeader/DecoratedHeader, Row/IndentedRow, and value fragments
such as Label, Status, Online, Selected/Unselected and DetailValue. MarkedExample supplies a
style-owned example marker when the service's existing grammar needs one; BulletedRow and
IndentedEmphasis preserve inline label layout without catalog-owned decoration. These components
express appearance only, never service visibility, authorization or business decisions.
