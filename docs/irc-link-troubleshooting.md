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

TCP keepalive is enabled for both plain and TLS links. The defaults are 60 seconds of idle time,
10 seconds between probes, and six probes. On a supported OS a silent drop is typically detected
in about two minutes. The loop-driver rollback does not change these socket settings.
