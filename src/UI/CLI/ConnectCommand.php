<?php

declare(strict_types=1);

namespace App\UI\CLI;

use App\Application\IRC\Connect\ConnectToServerCommand;
use App\Application\IRC\Connect\ConnectToServerHandlerInterface;
use App\Application\IRC\IrcSessionInterface;
use App\Domain\Udb\Repository\UdbAuthorityStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbOfflineTakeoverInterface;
use App\Infrastructure\Messenger\ConsumerProcessManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function function_exists;
use function sprintf;

use const SIGHUP;
use const SIGINT;
use const SIGTERM;

#[AsCommand(
    name: 'irc:connect',
    description: 'Establish a server-to-server link with an IRC daemon and enter the read loop.',
)]
class ConnectCommand extends Command
{
    /** Deterministic marker (sha256 of 'ares-fresh-seed') for the fresh SQL-seeded bootstrap. */
    private const string FRESH_SEED_FINGERPRINT = '43566f5c8bf11593153f78572a667c7ba7705b6b33bf0c3159273fc3661f73b3';

    public function __construct(
        private readonly ConnectToServerHandlerInterface $handler,
        private readonly ConsumerProcessManagerInterface $consumerManager,
        private readonly string $defaultServerName,
        private readonly string $defaultHost,
        private readonly int $defaultPort,
        private readonly string $defaultPassword,
        private readonly string $defaultDescription,
        private readonly string $defaultProtocol,
        private readonly bool $defaultUseTls,
        private readonly UdbAuthorityStateRepositoryInterface $udbAuthority,
        private readonly UdbOfflineTakeoverInterface $udbTakeover,
        private readonly string $udbDirectory,
        private readonly bool $udbBootstrapFromPeer = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'server-name',
                InputArgument::OPTIONAL,
                'Services FQDN presented to the IRCD (e.g. services.example.com). Defaults to IRC_SERVER_NAME.',
            )
            ->addArgument(
                'host',
                InputArgument::OPTIONAL,
                'IRCD hostname or IP address. Defaults to IRC_IRCD_HOST.',
            )
            ->addArgument(
                'port',
                InputArgument::OPTIONAL,
                'IRCD port number. Defaults to IRC_IRCD_PORT.',
            )
            ->addArgument(
                'password',
                InputArgument::OPTIONAL,
                'Server-link password. Defaults to IRC_LINK_PASSWORD.',
            )
            ->addArgument(
                'description',
                InputArgument::OPTIONAL,
                'Human-readable server description. Defaults to IRC_DESCRIPTION.',
            )
            ->addOption(
                'protocol',
                'p',
                InputOption::VALUE_REQUIRED,
                'S2S protocol to use: unreal, inspircd. Defaults to IRC_PROTOCOL.',
            )
            ->addOption(
                'tls',
                null,
                InputOption::VALUE_NONE,
                'Wrap the connection in TLS. Defaults to IRC_USE_TLS.',
            )
            ->addOption(
                'no-consumer',
                null,
                InputOption::VALUE_NONE,
                'Do not start the Messenger async consumer (for debugging).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverName = (string) ($input->getArgument('server-name') ?? $this->defaultServerName);
        $host = (string) ($input->getArgument('host') ?? $this->defaultHost);
        $port = (int) ($input->getArgument('port') ?? $this->defaultPort);
        $password = (string) ($input->getArgument('password') ?? $this->defaultPassword);
        $description = (string) ($input->getArgument('description') ?? $this->defaultDescription);
        $protocol = (string) ($input->getOption('protocol') ?? $this->defaultProtocol);
        $useTls = $input->getOption('tls') ? true : $this->defaultUseTls;

        if ('unrealudb' === $protocol && !$this->udbAuthority->isApproved()) {
            if ($this->udbBootstrapFromPeer) {
                $io->text('UDB bootstrap from peer enabled: the dataset will be collected from the IRCd during the first session.');
            } elseif (!$this->runUdbBootstrap($io)) {
                return Command::FAILURE;
            }
        }

        $io->title('Ares IRC Services');
        $io->definitionList(
            ['Server name' => $serverName],
            ['Host' => sprintf('%s:%d', $host, $port)],
            ['Protocol' => $protocol],
            ['TLS' => $useTls ? 'yes' : 'no'],
        );

        try {
            $io->text('Connecting...');

            $client = $this->handler->handle(new ConnectToServerCommand(
                serverName: $serverName,
                host: $host,
                port: $port,
                password: $password,
                description: $description,
                protocol: $protocol,
                useTls: $useTls,
            ));

            $this->registerSignalHandlers($client);

            if (!$input->getOption('no-consumer')) {
                $this->consumerManager->start();
            }

            try {
                $io->success(sprintf('Link established using protocol "%s". Entering read loop.', $protocol));

                $client->run();

                $io->warning('Connection closed by remote host.');
            } finally {
                $this->consumerManager->stop();
            }
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Bootstrap gate for UnrealUdb.
     *
     * Two modes: when IRC_UDB_DIRECTORY points at an offline UDB dataset the
     * startup runs the offline takeover (adoption of an existing store; the
     * operator must transfer remote datasets to a local path). Without a
     * directory Ares starts as a FRESH authority: the store seeds from SQL
     * on the first link (UdbStoreInitializer) and the IRCd adopts it over
     * the wire — no local dataset is required. An already approved dataset
     * is never re-imported (that would regress the live store to an older
     * snapshot); explicit `udb:takeover` remains available for manual
     * re-validation.
     */
    private function runUdbBootstrap(SymfonyStyle $io): bool
    {
        if ('' === $this->udbDirectory) {
            try {
                $this->udbAuthority->approve(self::FRESH_SEED_FINGERPRINT);
            } catch (Throwable $e) {
                $io->error(sprintf('UDB fresh bootstrap failed: %s', $e->getMessage()));

                return false;
            }

            $io->success('UDB fresh bootstrap approved: blocks seed from SQL on the first link (no offline dataset configured).');

            return true;
        }

        try {
            $fingerprint = $this->udbTakeover->takeover($this->udbDirectory);
        } catch (Throwable $e) {
            $io->error(sprintf('Automatic udb:takeover failed: %s', $e->getMessage()));

            return false;
        }

        $io->success(sprintf('Automatic udb:takeover approved the dataset (fingerprint %s).', $fingerprint));

        return true;
    }

    private function registerSignalHandlers(IrcSessionInterface $client): void
    {
        // @codeCoverageIgnoreStart
        // Cannot test PCNTL signal handlers in unit tests.
        // Signal handlers are process-level and execute asynchronously.
        // The body of each closure is only executed when a signal is received.
        if (!function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGINT, static function () use ($client): void {
            $client->disconnect('CTRL+C');
        });
        pcntl_signal(SIGTERM, static function () use ($client): void {
            $client->disconnect('SIGTERM');
        });
        // When terminal is closed, SIGHUP is sent; handle it so finally runs and consumer is stopped
        pcntl_signal(SIGHUP, static function () use ($client): void {
            $client->disconnect('SIGHUP');
        });
        // @codeCoverageIgnoreEnd
    }
}
