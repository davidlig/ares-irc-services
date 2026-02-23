<?php

declare(strict_types=1);

namespace App\UI\CLI;

use App\Application\IRC\Connect\ConnectToServerCommand;
use App\Application\IRC\Connect\ConnectToServerHandler;
use App\Application\IRC\IRCClient;
use App\Infrastructure\Messenger\ConsumerProcessManager;
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

use const SIGINT;
use const SIGTERM;

#[AsCommand(
    name: 'irc:connect',
    description: 'Establish a server-to-server link with an IRC daemon and enter the read loop.',
)]
class ConnectCommand extends Command
{
    public function __construct(
        private readonly ConnectToServerHandler $handler,
        private readonly ConsumerProcessManager $consumerManager,
        private readonly string $defaultServerName,
        private readonly string $defaultHost,
        private readonly int $defaultPort,
        private readonly string $defaultPassword,
        private readonly string $defaultDescription,
        private readonly string $defaultProtocol,
        private readonly bool $defaultUseTls,
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

    private function registerSignalHandlers(IRCClient $client): void
    {
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
    }
}
