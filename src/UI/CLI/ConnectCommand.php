<?php

declare(strict_types=1);

namespace App\UI\CLI;

use App\Application\Port\ConsumerProcessManagerInterface;
use App\Irc\Application\Connect\ConnectToServerCommand;
use App\Irc\Application\Connect\ConnectToServerHandlerInterface;
use App\Irc\Application\Connect\ProtocolConnectionPreflightInterface;
use App\Irc\Application\IrcSessionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function function_exists;
use function is_string;
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
    public function __construct(
        private readonly ConnectToServerHandlerInterface $handler,
        private readonly ConsumerProcessManagerInterface $consumerManager,
        private readonly ProtocolConnectionPreflightInterface $connectionPreflight,
        private readonly string $defaultServerName,
        private readonly string $defaultHost,
        private readonly int $defaultPort,
        private readonly string $defaultPassword,
        private readonly string $defaultDescription,
        private readonly bool $defaultUseTls,
        private readonly bool $defaultTlsVerifyPeer = true,
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
                'tls',
                null,
                InputOption::VALUE_NONE,
                'Wrap the connection in TLS. Defaults to IRC_USE_TLS.',
            )
            ->addOption(
                'insecure',
                null,
                InputOption::VALUE_NONE,
                'Disable TLS peer certificate verification. Defaults to IRC_TLS_VERIFY_PEER.',
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

        $rawServerName = $input->getArgument('server-name');
        $serverName = is_string($rawServerName) ? $rawServerName : $this->defaultServerName;

        $rawHost = $input->getArgument('host');
        $host = is_string($rawHost) ? $rawHost : $this->defaultHost;

        $rawPort = $input->getArgument('port');
        $port = is_numeric($rawPort) ? (int) $rawPort : $this->defaultPort;

        $rawPassword = $input->getArgument('password');
        $password = is_string($rawPassword) ? $rawPassword : $this->defaultPassword;

        $rawDescription = $input->getArgument('description');
        $description = is_string($rawDescription) ? $rawDescription : $this->defaultDescription;

        $useTls = $input->getOption('tls') ? true : $this->defaultUseTls;
        $tlsVerifyPeer = $input->getOption('insecure') ? false : $this->defaultTlsVerifyPeer;

        $preflight = $this->connectionPreflight->prepare();
        if (null !== $preflight->message) {
            if ($preflight->ready) {
                $io->success($preflight->message);
            } else {
                $io->error($preflight->message);
            }
        }
        if (!$preflight->ready) {
            return Command::FAILURE;
        }

        $io->title('Ares IRC Services');
        $io->definitionList(
            ['Server name' => $serverName],
            ['Host' => sprintf('%s:%d', $host, $port)],
            ['TLS' => $useTls ? ($tlsVerifyPeer ? 'yes' : 'yes (insecure)') : 'no'],
        );

        try {
            $io->text('Connecting...');

            $client = $this->handler->handle(new ConnectToServerCommand(
                serverName: $serverName,
                host: $host,
                port: $port,
                password: $password,
                description: $description,
                useTls: $useTls,
                tlsVerifyPeer: $tlsVerifyPeer,
            ));

            $this->registerSignalHandlers($client);

            if (!$input->getOption('no-consumer')) {
                $this->consumerManager->start();
            }

            try {
                $io->success(sprintf('Link established using protocol "%s". Entering read loop.', $client->getProtocolName()));

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
