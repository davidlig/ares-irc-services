<?php

declare(strict_types=1);

use App\ChanServ\Adapter\In\Irc\Bot\ChanServBot;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\In\Event\CoreSendNoticeAdapter;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Out\Connection\ConnectionStatus;
use App\Irc\Adapter\Out\Connection\SocketConnection;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdModule;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Kernel;
use App\MemoServ\Adapter\In\Irc\Bot\MemoServBot;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\NickServ\Adapter\In\Irc\Bot\NickServBot;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\OperServ\Adapter\In\Irc\Bot\OperServBot;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Contracts\Translation\TranslatorInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Counts connection API calls, not syscalls or TCP packets. Capture mode supplies
 * untimed output references; loopback mode delegates to the real SocketConnection.
 */
final class HelpBenchmarkConnection implements ConnectionInterface
{
    public string $payload = '';

    public int $singleCalls = 0;

    public int $batchCalls = 0;

    public ?SocketConnection $delegate = null;

    public function reset(): void
    {
        $this->payload = '';
        $this->singleCalls = 0;
        $this->batchCalls = 0;
    }

    public function connect(): void
    {
        $this->delegate?->connect();
    }

    public function disconnect(): void
    {
        $this->delegate?->disconnect();
    }

    /** @param string|list<string> $data */
    public function writeLine(array|string $data): void
    {
        if (is_string($data)) {
            ++$this->singleCalls;
            $this->payload .= $data . "\r\n";
        } else {
            ++$this->batchCalls;
            foreach ($data as $line) {
                $this->payload .= $line . "\r\n";
            }
        }
        $this->delegate?->writeLine($data);
    }

    public function readLine(): ?string
    {
        return $this->delegate?->readLine();
    }

    public function isConnected(): bool
    {
        return null === $this->delegate || $this->delegate->isConnected();
    }

    public function getStatus(): ConnectionStatus
    {
        return $this->delegate?->getStatus() ?? ConnectionStatus::Connected;
    }
}

/** Deterministic fixtures; no role repository, database or persistent permission cache. */
final readonly class HelpBenchmarkAuthorization implements NickServOperatorAccess, ChanServOperatorAccess, OperatorAuthorizationQuery
{
    public function isRoot(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool
    {
        return $identified && null !== $accountId && 'root' === strtolower($nickname);
    }

    public function isIrcop(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool
    {
        return $identified && null !== $accountId && ($ircOperator || $this->isRoot($nickname, $accountId, $identified, $ircOperator));
    }

    public function hasPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, string $permission): bool
    {
        return $this->isRoot($nickname, $accountId, $identified, $ircOperator)
            || ($this->isIrcop($nickname, $accountId, $identified, $ircOperator)
                && in_array($permission, ['nickserv.list', 'chanserv.list', 'operserv.gline'], true));
    }

    public function hasAnyPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, array $permissions): bool
    {
        return array_any($permissions, fn (string $permission): bool => $this->hasPermission($nickname, $accountId, $identified, $ircOperator, $permission));
    }

    public function identifiedAccount(OperatorActor $actor): AuthorizationDecision
    {
        return $actor->identified && null !== $actor->identifiedAccountId
            ? AuthorizationDecision::grantedBy(AuthorizationGrant::IdentifiedAccount) : AuthorizationDecision::denied();
    }

    public function ircOperator(OperatorActor $actor): AuthorizationDecision
    {
        return $this->isIrcop($actor->nickname, $actor->identifiedAccountId, $actor->identified, $actor->ircOperator)
            ? AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus) : AuthorizationDecision::denied();
    }

    public function root(OperatorActor $actor): AuthorizationDecision
    {
        return $this->isRoot($actor->nickname, $actor->identifiedAccountId, $actor->identified, $actor->ircOperator)
            ? AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity) : AuthorizationDecision::denied();
    }

    public function permission(OperatorActor $actor, string $permission): AuthorizationDecision
    {
        return $this->hasPermission($actor->nickname, $actor->identifiedAccountId, $actor->identified, $actor->ircOperator, $permission)
            ? AuthorizationDecision::grantedBy(AuthorizationGrant::RolePermission) : AuthorizationDecision::denied();
    }
}

/** @template T of object
 * @param class-string<T> $class
 *
 * @return T
 */
function helpBenchmarkService(ContainerInterface $container, string $class): object
{
    $service = $container->get($class);
    if (!$service instanceof $class) {
        throw new RuntimeException('Unexpected service type: ' . $class);
    }

    return $service;
}

/** @param list<string> $args */
function helpBenchmarkExecute(ContainerInterface $container, string $domain, string $actor, string $locale, array $args): void
{
    $identified = 'ordinary' !== $actor;
    $sender = new SenderView('9AAUSER01', $actor, 'bench', 'localhost', 'localhost', '*', $identified, 'oper' === $actor, '9AA', '127.0.0.1', 'oper' === $actor ? 'o' : '');
    $translator = helpBenchmarkService($container, TranslatorInterface::class);
    $nicks = helpBenchmarkService($container, ServiceNicknameRegistry::class);

    switch ($domain) {
        case 'nickserv':
            $account = null;
            if ($identified) {
                $account = RegisteredNick::createPending($actor, 'fixture-hash', 'bench@example.invalid', $locale, new DateTimeImmutable('2030-01-01'), new DateTimeImmutable('2020-01-01'));
                // Doctrine normally assigns this persistence identity; no ORM is used here.
                new ReflectionProperty(RegisteredNick::class, 'id')->setValue($account, 1);
            }
            $registry = helpBenchmarkService($container, NickServCommandRegistry::class);
            $context = new NickServContext($sender, $account, 'HELP', $args, helpBenchmarkService($container, NickServNotifierInterface::class), $translator, $locale, 'UTC', 'NOTICE', $registry, new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $nicks);
            $registry->find('HELP')?->execute($context);
            break;
        case 'chanserv':
            $registry = helpBenchmarkService($container, ChanServCommandRegistry::class);
            $module = helpBenchmarkService($container, InspIRCdModule::class);
            $context = new ChanServContext($sender, $identified ? new ChanAccountView(1, $actor, $locale) : null, 'HELP', $args, helpBenchmarkService($container, ChanServNotifierInterface::class), $translator, $locale, 'UTC', 'NOTICE', $registry, helpBenchmarkService($container, ChannelLookupPort::class), $module->getChannelModeSupport(), helpBenchmarkService($container, NetworkUserLookupPort::class), $nicks);
            $registry->find('HELP')?->execute($context);
            break;
        case 'memoserv':
            $registry = helpBenchmarkService($container, MemoServCommandRegistry::class);
            $context = new MemoServContext($sender, $identified ? new MemoAccountView(1, $actor, $locale) : null, 'HELP', $args, helpBenchmarkService($container, MemoServNotifierInterface::class), $translator, $locale, 'UTC', 'NOTICE', $registry, $nicks);
            $registry->find('HELP')?->execute($context);
            break;
        case 'operserv':
            $registry = helpBenchmarkService($container, OperServCommandRegistry::class);
            $context = new OperServContext($sender, $identified ? new NickAccountData(1, $actor, $locale) : null, 'HELP', $args, helpBenchmarkService($container, OperServNotifierInterface::class), $translator, $locale, 'UTC', 'NOTICE', $registry, $nicks, helpBenchmarkService($container, OperatorAuthorizationQuery::class));
            $registry->find('HELP')?->execute($context);
            break;
        default:
            throw new InvalidArgumentException('Unknown service: ' . $domain);
    }
}

/** @param resource $stream */
function helpBenchmarkWrite($stream, string $payload): void
{
    while ('' !== $payload) {
        $written = fwrite($stream, $payload);
        if (false === $written || 0 === $written) {
            throw new RuntimeException('Loopback/control write failed.');
        }
        $payload = substr($payload, $written);
    }
}

/**
 * Exact byte framing comes over a separate local control stream. No markers are
 * injected into IRC output, and no polling, sleeps or idle timeout end a response.
 *
 * @param resource $server
 * @param resource $control
 */
function helpBenchmarkReceive($server, $control): void
{
    $socket = stream_socket_accept($server, 10);
    if (false === $socket) {
        throw new RuntimeException('Loopback accept failed.');
    }
    stream_set_timeout($socket, 10);
    while (false !== ($request = fgets($control))) {
        $remaining = (int) trim($request);
        if (0 === $remaining) {
            break;
        }
        $payload = '';
        $first = null;
        $at = hrtime(true);
        while ($remaining > 0) {
            $chunk = fread($socket, min(65536, $remaining));
            if (false === $chunk || '' === $chunk) {
                throw new RuntimeException('Incomplete loopback response.');
            }
            $at = hrtime(true);
            $payload .= $chunk;
            $remaining -= strlen($chunk);
            if (null === $first && str_contains($payload, "\r\n")) {
                $first = $at;
            }
        }
        helpBenchmarkWrite($control, json_encode(['first_ns' => $first, 'last_ns' => $at, 'sha256' => hash('sha256', $payload)], \JSON_THROW_ON_ERROR) . "\n");
    }
    fclose($socket);
}

/** @param list<float> $samples
 * @return array{median: float, p95: float}
 */
function helpBenchmarkSummary(array $samples): array
{
    sort($samples, \SORT_NUMERIC);
    $count = count($samples);
    $middle = intdiv($count, 2);

    return [
        'median' => 0 === $count % 2 ? ($samples[$middle - 1] + $samples[$middle]) / 2 : $samples[$middle],
        'p95' => $samples[(int) ceil(0.95 * $count) - 1],
    ];
}

$options = getopt('', ['iterations:', 'warmups:', 'locale:', 'locales:', 'help']);
$iterations = filter_var($options['iterations'] ?? 1000, \FILTER_VALIDATE_INT);
$warmups = filter_var($options['warmups'] ?? 100, \FILTER_VALIDATE_INT);
$locale = $options['locale'] ?? 'es';
$catalogLocales = $options['locales'] ?? 'all';
if (!is_string($catalogLocales)) {
    fwrite(\STDERR, "--locales requires one comma-separated string.\n");
    exit(2);
}
$locales = 'all' === $catalogLocales ? RegisteredNick::SUPPORTED_LANGUAGES : explode(',', $catalogLocales);
if (isset($options['help']) || false === $iterations || $iterations < 1 || false === $warmups || $warmups < 0
    || !in_array($locale, RegisteredNick::SUPPORTED_LANGUAGES, true)
    || [] !== array_diff($locales, RegisteredNick::SUPPORTED_LANGUAGES)
) {
    fwrite(\STDERR, "Usage: XDEBUG_MODE=off php scripts/benchmark-help.php [--iterations=1000] [--warmups=100] [--locale=es] [--locales=all|en,es]\n");
    exit(isset($options['help']) ? 0 : 2);
}
if (!extension_loaded('pcntl')) {
    fwrite(\STDERR, "The loopback receiver requires the pcntl extension (Linux/macOS CLI).\n");
    exit(2);
}

$kernel = null;
$connection = new HelpBenchmarkConnection();
$child = null;
$control = null;
$server = null;
$exitCode = 0;
try {
    // Load only committed defaults, never .env.local, .env.*.local or ambient credentials.
    $dotenv = new Dotenv();
    foreach (['.env', '.env.test'] as $defaultsFile) {
        $defaults = file_get_contents(dirname(__DIR__) . '/' . $defaultsFile);
        if (false === $defaults) {
            throw new RuntimeException('Cannot read committed defaults: ' . $defaultsFile);
        }
        $dotenv->populate($dotenv->parse($defaults), true);
    }
    $dotenv->populate([
        'APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_SECRET' => 'help-benchmark-fixture',
        'DATABASE_URL' => 'sqlite:///:memory:', 'MAILER_DSN' => 'null://null',
        'MESSENGER_TRANSPORT_DSN' => 'in-memory://', 'IRC_PROTOCOL' => 'inspircd',
        'IRC_IRCD_HOST' => '127.0.0.1', 'IRC_IRCD_PORT' => '1',
        'IRC_LINK_PASSWORD' => 'unused-fixture', 'IRC_USE_TLS' => 'false', 'IRC_SERVER_SID' => '9AA',
        'NICKSERV_NICK' => 'NickServ', 'CHANSERV_NICK' => 'ChanServ',
        'MEMOSERV_NICK' => 'MemoServ', 'OPERSERV_NICK' => 'OperServ',
        'NICKSERV_INACTIVITY_EXPIRY_DAYS' => '90', 'CHANSERV_INACTIVITY_EXPIRY_DAYS' => '45',
    ], true);
    $kernel = new Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof TestContainer) {
        throw new RuntimeException('Test service container is unavailable.');
    }
    $authorization = new HelpBenchmarkAuthorization();
    $container->set(NickServOperatorAccess::class, $authorization);
    $container->set(ChanServOperatorAccess::class, $authorization);
    $container->set(OperatorAuthorizationQuery::class, $authorization);
    $holder = helpBenchmarkService($container, ActiveConnectionHolder::class);
    $container->set(SendNoticePort::class, new CoreSendNoticeAdapter($holder));
    $holder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '9AA'));
    $registries = [
        'nickserv' => helpBenchmarkService($container, NickServCommandRegistry::class),
        'chanserv' => helpBenchmarkService($container, ChanServCommandRegistry::class),
        'memoserv' => helpBenchmarkService($container, MemoServCommandRegistry::class),
        'operserv' => helpBenchmarkService($container, OperServCommandRegistry::class),
    ];
    // Initialize real bots' stable UIDs with no active module: nothing is introduced.
    foreach ([NickServBot::class, ChanServBot::class, MemoServBot::class, OperServBot::class] as $notifierClass) {
        helpBenchmarkService($container, $notifierClass)->onBurstComplete(new ServiceIntroductionRequestedEvent('9AA'));
    }
    $holder->setProtocolModule(helpBenchmarkService($container, InspIRCdModule::class));
    $outputs = [];
    $counts = [];
    foreach ($registries as $domain => $registry) {
        $counts[$domain] = count($registry->all());
        $cases = [[]];
        foreach ($registry->all() as $command) {
            $cases[] = [$command->getName()];
            foreach ($command->getSubCommandHelp() as $subcommand) {
                $cases[] = [$command->getName(), $subcommand['name']];
            }
        }
        if ('nickserv' === $domain) {
            array_push($cases, ['SET', 'TIMEZONE', 'Europe'], ['SET', 'TIMEZONE', 'InvalidRegion']);
        }
        foreach (array_unique($locales) as $outputLocale) {
            foreach (['ordinary', 'oper', 'root'] as $actor) {
                foreach ($cases as $args) {
                    $connection->reset();
                    helpBenchmarkExecute($container, $domain, $actor, $outputLocale, $args);
                    $outputs[] = [
                        'service' => $domain, 'locale' => $outputLocale, 'actor' => $actor,
                        'command' => trim('HELP ' . implode(' ', $args)),
                        'lines' => substr_count($connection->payload, "\r\n"),
                        'bytes' => strlen($connection->payload), 'sha256' => hash('sha256', $connection->payload),
                        'connection_calls' => $connection->singleCalls + $connection->batchCalls,
                    ];
                }
            }
        }
    }

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    if (false === $server || false === $pair) {
        throw new RuntimeException('Cannot create local benchmark streams.');
    }
    $address = stream_socket_get_name($server, false);
    if (false === $address) {
        throw new RuntimeException('Cannot determine loopback port.');
    }
    $child = pcntl_fork();
    if (-1 === $child) {
        throw new RuntimeException('Cannot start local receiver.');
    }
    if (0 === $child) {
        fclose($pair[0]);
        try {
            helpBenchmarkReceive($server, $pair[1]);
            exit(0);
        } catch (Throwable $error) {
            fwrite(\STDERR, $error->getMessage() . "\n");
            exit(1);
        }
    }
    fclose($pair[1]);
    fclose($server);
    $server = null;
    $control = $pair[0];
    stream_set_timeout($control, 15);
    $portSuffix = strrchr($address, ':');
    if (false === $portSuffix) {
        throw new RuntimeException('Loopback address has no port.');
    }
    $socket = new SocketConnection('127.0.0.1', (int) substr($portSuffix, 1));
    $socket->connect();
    $timings = [];
    foreach (array_keys($registries) as $domain) {
        $cases = [[], ['HELP']];
        if ('nickserv' === $domain) {
            array_push($cases, ['SET', 'TIMEZONE'], ['SET', 'TIMEZONE', 'Europe'], ['SET', 'TIMEZONE', 'InvalidRegion']);
        }
        foreach ($cases as $args) {
            $connection->delegate = null;
            $connection->reset();
            helpBenchmarkExecute($container, $domain, 'root', $locale, $args);
            $reference = $connection->payload;
            if ('' === $reference) {
                throw new RuntimeException('HELP unexpectedly produced no output.');
            }
            $digest = hash('sha256', $reference);
            $connection->delegate = $socket;
            $firstSamples = [];
            $lastSamples = [];
            for ($iteration = 0; $iteration < $iterations + $warmups; ++$iteration) {
                $connection->reset();
                helpBenchmarkWrite($control, strlen($reference) . "\n");
                $start = hrtime(true);
                helpBenchmarkExecute($container, $domain, 'root', $locale, $args);
                $reply = fgets($control);
                if (false === $reply) {
                    throw new RuntimeException('Receiver did not complete the response.');
                }
                $received = json_decode($reply, true, flags: \JSON_THROW_ON_ERROR);
                if (!is_array($received) || !is_string($received['sha256'] ?? null)
                    || !is_int($received['first_ns'] ?? null) || !is_int($received['last_ns'] ?? null)
                ) {
                    throw new RuntimeException('Invalid receiver report.');
                }
                if ($reference !== $connection->payload || $digest !== $received['sha256']) {
                    throw new RuntimeException('Output changed or loopback bytes were lost.');
                }
                if ($iteration >= $warmups) {
                    $firstSamples[] = ($received['first_ns'] - $start) / 1_000_000;
                    $lastSamples[] = ($received['last_ns'] - $start) / 1_000_000;
                }
            }
            $timings[] = [
                'service' => $domain, 'locale' => $locale, 'actor' => 'root',
                'command' => trim('HELP ' . implode(' ', $args)),
                'lines' => substr_count($reference, "\r\n"), 'bytes' => strlen($reference), 'sha256' => $digest,
                'write_line_string_calls' => $connection->singleCalls, 'write_line_array_calls' => $connection->batchCalls,
                'first_line_ms' => helpBenchmarkSummary($firstSamples), 'last_line_ms' => helpBenchmarkSummary($lastSamples),
            ];
        }
    }
    $report = [
        'php' => \PHP_VERSION, 'os' => \PHP_OS_FAMILY, 'service_version' => $container->getParameter('services.version'),
        'iterations' => $iterations, 'warmups' => $warmups, 'timing_locale' => $locale,
        'catalog_locales' => array_values(array_unique($locales)), 'registered_commands' => $counts,
        'settings' => [
            'xdebug_configured_mode' => ini_get('xdebug.mode'),
            'xdebug_mode_override' => getenv('XDEBUG_MODE'),
            'xdebug_effective_modes' => function_exists('xdebug_info') ? xdebug_info('mode') : [],
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'timezone_db' => timezone_version_get(),
        ],
        'boundary' => 'Warm HELP execution through real commands, translation, bots, CoreSendNotice and SocketConnection; first/last complete CRLF line observed by a concurrent loopback receiver. Counts are connection API calls, not socket writes/packets. Kernel/registry startup, SQL, production logging, IRCd and client excluded; reference rendering already warms translation/timezone catalogs.',
        'timings' => $timings, 'outputs' => $outputs,
    ];
    fwrite(\STDOUT, json_encode($report, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $error) {
    fwrite(\STDERR, 'HELP benchmark: ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    $connection->disconnect();
    if (is_resource($control)) {
        fclose($control);
    }
    if (is_resource($server)) {
        fclose($server);
    }
    if (null !== $child && $child > 0) {
        pcntl_waitpid($child, $status);
    }
    $kernel?->shutdown();
}
exit($exitCode);
