# OperServ MOTD delivery statistics

OperServ keeps active MOTDs in memory and batches delivery counters during each
maintenance cycle and when the IRC connection closes. An abrupt process crash
may lose the deliveries counted since the last successful flush. These counters
are statistics; this does not affect the configured MOTDs or their delivery.
