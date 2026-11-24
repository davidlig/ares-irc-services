# IRC link troubleshooting

Ares logs the active Revolt driver in the `loop_driver` field of `Entering read loop.`.
On PHP 8.6, Revolt selects `IoPollDriver` when no higher-priority driver is available.

To roll back the event loop while diagnosing a problem, set this in the Ares process environment
and restart the service:

```sh
export REVOLT_DRIVER='Revolt\EventLoop\Driver\StreamSelectDriver'
```

For containers, pass the same variable to the Ares container. Confirm the new driver in the
startup log. Remove the variable and restart to restore automatic selection.

TCP keepalive is enabled for both plain and TLS links. The defaults are 60 seconds of idle time, 10
seconds between probes, and six probes; the connector rejects values outside the Linux limits
(1–32767 seconds for the timers, 1–127 probes) because the kernel would silently ignore them.
Keepalive probes pause while sent data is waiting for acknowledgement, so the link also sets
`TCP_USER_TIMEOUT` to the same 120 seconds. On Linux with `ext-sockets` a silent drop is typically
detected in about two minutes either way. Without `ext-sockets`, or on an OS without
`TCP_USER_TIMEOUT`, each connection attempt logs a warning that `TCP_USER_TIMEOUT` is not set, and a
drop with pending data waits for TCP retransmissions to give up (about 15 minutes). The loop-driver
rollback does not change these socket settings.
