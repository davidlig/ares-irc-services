<?php

declare(strict_types=1);

use App\Irc\Adapter\Network\Event\UserNickChangeReceivedEvent;
use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\Network\ServerDelinkedSubscriber;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Out\Connection\ConnectionStatus;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Irc\Adapter\Protocol\NetworkStateAdapterInterface;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneProtocolHandler;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionController;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Runtime\SessionEventPump;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class LatencySamples
{
    private const int MAX_SAMPLES = 100_000;

    /** @var list<float> */
    private array $values = [];

    private int $count = 0;

    public function record(float $milliseconds): void
    {
        $slot = $this->count % self::MAX_SAMPLES;
        ++$this->count;
        $this->values[$slot] = $milliseconds;
    }

    /** @return array{observations: int, sampled: int, p95_ms: float, p99_ms: float} */
    public function summary(): array
    {
        sort($this->values, \SORT_NUMERIC);

        return [
            'observations' => $this->count,
            'sampled' => count($this->values),
            'p95_ms' => percentile($this->values, 0.95),
            'p99_ms' => percentile($this->values, 0.99),
        ];
    }
}

$options = getopt('', ['protocol:', 'users:', 'worker-pid::', 'source-root::', 'steady-seconds::', 'sample-ms::']);
$protocolName = $options['protocol'] ?? '';
$userCount = filter_var($options['users'] ?? null, \FILTER_VALIDATE_INT);
$steadySeconds = filter_var($options['steady-seconds'] ?? 0, \FILTER_VALIDATE_FLOAT);
$sampleMilliseconds = filter_var($options['sample-ms'] ?? 1000, \FILTER_VALIDATE_INT);
if (!in_array($protocolName, ['inspircd', 'unrealstandalone', 'unrealudb'], true)
    || false === $userCount || $userCount < 1
    || false === $steadySeconds || $steadySeconds < 0
    || false === $sampleMilliseconds || $sampleMilliseconds < 1
) {
    fwrite(\STDERR, "Usage: php scripts/measure-connected-services.php --protocol=inspircd|unrealstandalone|unrealudb --users=1000 [--steady-seconds=30] [--sample-ms=1000] [--worker-pid=PID] [--source-root=/path/to/checkout]\n");
    exit(2);
}

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
if (isset($options['source-root'])) {
    $sourceRoot = realpath((string) $options['source-root']);
    if (false === $sourceRoot || !is_dir($sourceRoot . '/src')) {
        fwrite(\STDERR, "--source-root must point to a checkout with src/\n");
        exit(2);
    }
    $loader->setPsr4('App\\', $sourceRoot . '/src/');
}

$users = new InMemoryNetworkUserRepository();
$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new NetworkStateSubscriber($users, new InMemoryChannelRepository()));
$dispatcher->addSubscriber(new ServerDelinkedSubscriber($users, $dispatcher));
$dispatcher->addListener(UserNickChangeReceivedEvent::class, static function (UserNickChangeReceivedEvent $event) use ($users): void {
    $uid = new Uid($event->sourceId);
    $user = $users->findByUid($uid);
    if (null !== $user) {
        $users->updateNick($uid, $user->getNick(), new Nick($event->newNickStr));
    }
});
$dispatcher->addListener(UserQuitReceivedEvent::class, static function (UserQuitReceivedEvent $event) use ($users): void {
    $users->removeByUid(new Uid($event->sourceId));
});

/* @var ProtocolHandlerInterface $protocol */
/* @var NetworkStateAdapterInterface $network */
switch ($protocolName) {
    case 'inspircd':
        $protocol = new InspIRCdProtocolHandler();
        $network = new InspIRCdNetworkStateAdapter($dispatcher);
        break;
    case 'unrealstandalone':
        $protocol = new UnrealStandaloneProtocolHandler();
        $network = new UnrealStandaloneNetworkStateAdapter($dispatcher);
        break;
    default:
        $coordinator = new class implements UdbSessionController {
            public function setEventPump(?SessionEventPump $eventPump): void {}

            public function setOwnName(string $ownName): void {}

            public function onRemoteServer(string $sid, string $serverName): void {}

            public function onLinkReady(ConnectionInterface $connection): void {}

            public function handleFrame(UdbFrame $frame, ConnectionInterface $connection): void {}

            public function tick(?ConnectionInterface $connection = null): void {}

            public function reset(): void {}
        };
        $protocol = new UnrealUdbProtocolHandler('0A0', $coordinator);
        $network = new UnrealUdbNetworkStateAdapter($dispatcher);
}

$connection = new class implements ConnectionInterface {
    public function connect(): void {}

    public function disconnect(): void {}

    public function writeLine(string $data): void {}

    public function readLine(): ?string
    {
        return null;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function getStatus(): ConnectionStatus
    {
        return ConnectionStatus::Connected;
    }
};

$pump = new SessionEventPump();
$latencies = [
    'burst' => new LatencySamples(),
    'steady' => new LatencySamples(),
    'squit' => new LatencySamples(),
    'reconnect' => new LatencySamples(),
];
$stages = [];
$memorySamples = [];
$observedQueueMax = 0;
$workerPid = isset($options['worker-pid']) ? (int) $options['worker-pid'] : null;
$initial = snapshot($users->count(), $workerPid);

$producer = \Amp\async(static function () use ($userCount, $protocolName, $steadySeconds, $sampleMilliseconds, $protocol, $network, $connection, $users, $pump, $latencies, &$stages, &$memorySamples, &$observedQueueMax, $workerPid): void {
    $stageLines = [
        'burst' => static function () use ($userCount, $protocolName): iterable {
            for ($i = 0; $i < $userCount; ++$i) {
                yield uidLine($protocolName, $i);
            }
        },
        'steady' => static function () use ($userCount, $steadySeconds): iterable {
            $deadline = microtime(true) + $steadySeconds;
            $cycle = 0;
            do {
                $suffix = 0 === $cycle % 2 ? 'A' : 'B';
                for ($i = 0; $i < $userCount; ++$i) {
                    yield ':U' . sprintf('%06d', $i) . ' NICK User' . $i . $suffix . ' 1700000001';
                }
                ++$cycle;
            } while (0.0 < $steadySeconds && microtime(true) < $deadline);
        },
        'squit' => static function (): iterable {
            yield ':001 SQUIT 002 :deterministic split';
        },
        'reconnect' => static function () use ($userCount, $protocolName): iterable {
            for ($i = 1; $i < $userCount; $i += 2) {
                yield uidLine($protocolName, $i);
            }
        },
    ];

    foreach ($stageLines as $name => $lineFactory) {
        $startedNs = hrtime(true);
        $startedCpu = cpuSeconds();
        $nextSampleAt = microtime(true);
        $count = 0;
        foreach ($lineFactory() as $line) {
            // The same bounded feed is used against HEAD and the working tree.
            while ($pump->getQueueSize() >= SessionEventPump::READER_LOW_WATER) {
                \Amp\delay(0.0001);
            }
            $queuedNs = hrtime(true);
            $pump->enqueue(static function () use ($line, $name, $queuedNs, $protocol, $network, $connection, &$latencies): void {
                $message = $protocol->parseRawLine($line);
                $protocol->handleIncoming($message, $connection);
                $network->handleMessage($message);
                $latencies[$name]->record((hrtime(true) - $queuedNs) / 1_000_000);
            });
            $observedQueueMax = max($observedQueueMax, $pump->getQueueSize());
            ++$count;

            $now = microtime(true);
            if ('steady' === $name && 0.0 < $steadySeconds && $now >= $nextSampleAt) {
                $memorySamples[] = [
                    'elapsed_seconds' => round((hrtime(true) - $startedNs) / 1_000_000_000, 3),
                    ...snapshot($users->count(), $workerPid),
                ];
                $nextSampleAt = $now + $sampleMilliseconds / 1000;
            }
        }

        $pump->enqueue(static function () use ($name, $count, $startedNs, $startedCpu, $users, $workerPid, &$latencies, &$stages): void {
            $seconds = (hrtime(true) - $startedNs) / 1_000_000_000;
            $latency = $latencies[$name]->summary();
            $stages[$name] = [
                'messages' => $count,
                'seconds' => round($seconds, 6),
                'messages_per_second' => round($count / max($seconds, 0.000001), 1),
                'cpu_seconds' => round(cpuSeconds() - $startedCpu, 6),
                'latency' => $latency,
                'memory' => snapshot($users->count(), $workerPid),
            ];
        });
    }

    $pump->enqueue(static function () use ($pump): void {
        $pump->stop();
    });
});

$pump->drain();
$producer->await();

echo json_encode([
    'protocol' => $protocolName,
    'users_requested' => $userCount,
    'steady_seconds_requested' => $steadySeconds,
    'memory_sample_ms' => $sampleMilliseconds,
    'source_root' => $sourceRoot ?? dirname(__DIR__),
    'scope' => 'real protocol parser + network state adapter + in-memory subscribers + serialized pump; excludes socket, Doctrine and Messenger',
    'initial' => $initial,
    'stages' => $stages,
    'steady_memory_samples' => $memorySamples,
    'pump_max_queue' => method_exists($pump, 'getPeakQueueSize') ? $pump->getPeakQueueSize() : $observedQueueMax,
    'final_users' => $users->count(),
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";

function uidLine(string $protocol, int $index): string
{
    $uid = 'U' . sprintf('%06d', $index);
    $nick = 'User' . $index;
    $sid = 0 === $index % 2 ? '001' : '002';

    return 'inspircd' === $protocol
        ? ":$sid UID $uid 1700000000 $nick host.example cloak.example user user 127.0.0.1 1700000000 +i :Load User"
        : ":$sid UID $nick 0 1700000000 user host.example $uid 0 +i host.example cloak.example fwAAAQ== :Load User";
}

function percentile(array $samples, float $fraction): float
{
    if ([] === $samples) {
        return 0.0;
    }

    return round($samples[(int) ceil(count($samples) * $fraction) - 1], 3);
}

function cpuSeconds(): float
{
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000;
}

function rssBytes(int $pid): ?int
{
    $status = @file_get_contents('/proc/' . $pid . '/status');
    if (false === $status || 1 !== preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $matches)) {
        return null;
    }

    return (int) $matches[1] * 1024;
}

function snapshot(int $users, ?int $workerPid): array
{
    return [
        'users_live' => $users,
        'php_live_bytes' => memory_get_usage(false),
        'php_allocated_bytes' => memory_get_usage(true),
        'php_peak_live_bytes' => memory_get_peak_usage(false),
        'php_peak_allocated_bytes' => memory_get_peak_usage(true),
        'parent_rss_bytes' => rssBytes(getmypid()),
        'worker_rss_bytes' => null !== $workerPid ? rssBytes($workerPid) : null,
    ];
}
