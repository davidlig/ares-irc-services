# HELP transport benchmark

Measure local HELP latency and verify byte-for-byte presentation stability across a refactor:

```bash
XDEBUG_MODE=off php scripts/benchmark-help.php \
  --iterations=1000 --warmups=100 --locale=es --locales=all \
  > var/help-before.json

# Run the same command after the change, on the same machine and PHP settings.
XDEBUG_MODE=off php scripts/benchmark-help.php \
  --iterations=1000 --warmups=100 --locale=es --locales=all \
  > var/help-after.json
```

Requires the installed Composer dependencies and `pcntl` (Linux/macOS PHP CLI). No IRC server,
database, Docker container, credentials or remote access is needed. The only TCP connection is
an ephemeral listener on `127.0.0.1`; a separate local receiver process reads exact response lengths.
The harness never loads `.env.local` or environment-specific local overrides. It replaces committed
defaults with SQLite in memory, discarded mail and fixed benchmark identities. It does not execute
commands other than HELP, start the daemon or query persistence.

## Read the results

- `registered_commands` comes from the **complete real runtime registries**, not a synthetic
  metadata subset. The current 2.3.0 catalog has 23 NickServ, 29 ChanServ, 8 MemoServ and 8 OperServ
  commands.
- `outputs` records line count, byte count and SHA-256 of the complete CRLF-delimited wire output
  for general HELP, every registered command and subcommand, plus NickServ TIMEZONE region/invalid
  region variants. It covers all 14 locales by default and three deterministic actors: unidentified
  ordinary user, identified IRCop with only LIST/GLINE permissions, and identified Root without `+o`.
- `timings` measures Root's general HELP and `HELP HELP` in each service, plus the three TIMEZONE
  variants. `--locale=es` selects this timing workload; `--locales=all` selects the untimed output
  catalog, independently. Every timed response is checked against its reference at both ends.
- `first_line_ms` and `last_line_ms` contain median and nearest-rank p95 from request execution
  through the receiver observing the first/last **complete** IRC line. Startup and warmups are
  excluded. Reference rendering already warms translation/timezone catalogs: these are hot-path
  measurements, **not cold-start measurements**.
- `write_line_calls` / `write_lines_calls` and `outputs[].connection_calls` count connection API
  calls, **not underlying socket writes, packets or syscalls**. Batching reduces these calls;
  network backpressure or chunking can still cause multiple underlying writes.

The measured path uses real HELP commands, Symfony translations, service bots, CoreSendNotice and
SocketConnection. Context fixture construction is included; container startup, SQL, production
logging, an actual IRCd and the client's rendering/network are excluded. Loopback timing alone does
not prove a production speedup. Never use a timing threshold as a correctness test.

Compare output identity while ignoring timings and the deliberately changing connection-call count:

```bash
php -r '
$before = json_decode(file_get_contents("var/help-before.json"), true, flags: JSON_THROW_ON_ERROR);
$after = json_decode(file_get_contents("var/help-after.json"), true, flags: JSON_THROW_ON_ERROR);
$identity = static fn (array $row): array => array_diff_key($row, ["connection_calls" => true]);
if ($before["registered_commands"] !== $after["registered_commands"]
    || array_map($identity, $before["outputs"]) !== array_map($identity, $after["outputs"])) {
    fwrite(STDERR, "HELP output changed\n"); exit(1);
}
echo "All HELP outputs match\n";
'
```

For a quick smoke run, use `--iterations=2 --warmups=1 --locales=en`. Keep the machine, PHP binary,
Xdebug mode, OPcache/JIT settings, timezone database, catalogs, actors and command counts identical
for before/after timing comparisons; the report includes these runtime settings.
Xdebug's configured INI mode, `XDEBUG_MODE` override and effective modes are reported separately:
the override can disable Xdebug while `ini_get('xdebug.mode')` still says `develop`.
See [Xdebug mode precedence](https://xdebug.org/docs/all_settings#mode) and
[effective-mode inspection](https://xdebug.org/docs/develop#xdebug_info).
