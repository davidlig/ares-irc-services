# Connected services load harness

Run the local deterministic replay against one protocol and one of the standard user counts:

```bash
php scripts/measure-connected-services.php --protocol=inspircd --users=1000
php scripts/measure-connected-services.php --protocol=unrealstandalone --users=10000
php scripts/measure-connected-services.php --protocol=unrealudb --users=50000
```

Add a sustained state-changing phase and periodic memory samples with:

```bash
php scripts/measure-connected-services.php \
  --protocol=unrealudb \
  --users=50000 \
  --steady-seconds=1800 \
  --sample-ms=1000 \
  --worker-pid=12345
```

The JSON output reports PHP live, allocated, and peak memory; parent and optional worker RSS; CPU time; maximum serialized queue depth; throughput; latency p95/p99; and memory samples during the steady phase. The replay covers burst, changing nick state under steady load, SQUIT cleanup, and reconnect.

The measured boundary is the real protocol parser, protocol-specific network state adapter, in-memory repositories and event subscribers, and the serialized session pump. It excludes socket I/O, Doctrine queries and Messenger handling, so the reported latency is adapter-path latency rather than end-to-end production latency.

## Compare with `HEAD`

Use the same harness and dependencies for both runs while loading application classes from a clean archive for the baseline:

```bash
baseline_dir=$(mktemp -d /tmp/ares-head-baseline-XXXXXX)
git archive HEAD | tar -x -C "$baseline_dir"

php scripts/measure-connected-services.php \
  --protocol=inspircd --users=10000 --steady-seconds=30 \
  > /tmp/ares-current.json

php scripts/measure-connected-services.php \
  --protocol=inspircd --users=10000 --steady-seconds=30 \
  --source-root="$baseline_dir" \
  > /tmp/ares-head.json
```

Repeat each profile three times before comparing memory stabilization, throughput and latency. The `source_root` field in each result records which source tree was loaded.
