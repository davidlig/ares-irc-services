<?php

declare(strict_types=1);

namespace App\UI\CLI;

use App\Application\IRC\Connect\ConnectToServerCommand;
use App\Application\IRC\Connect\ConnectToServerHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'irc:connect',
    description: 'Establish a server-to-server link with an IRC daemon and enter the read loop.',
)]
class ConnectCommand extends Command
{
    public function __construct(
        private readonly ConnectToServerHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'server-name',
                InputArgument::REQUIRED,
                'Services FQDN presented to the IRCD (e.g. services.example.com)',
            )
            ->addArgument('host', InputArgument::REQUIRED, 'IRCD hostname or IP address')
            ->addArgument('port', InputArgument::REQUIRED, 'IRCD port number')
            ->addArgument('password', InputArgument::REQUIRED, 'Server-link password')
            ->addArgument('description', InputArgument::REQUIRED, 'Human-readable server description')
            ->addOption(
                'protocol',
                'p',
                InputOption::VALUE_REQUIRED,
                'S2S protocol to use. Available: unreal, inspircd',
                'unreal',
            )
            ->addOption('tls', null, InputOption::VALUE_NONE, 'Wrap the connection in TLS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverName = (string) $input->getArgument('server-name');
        $host = (string) $input->getArgument('host');
        $port = (int) $input->getArgument('port');
        $password = (string) $input->getArgument('password');
        $description = (string) $input->getArgument('description');
        $protocol = (string) $input->getOption('protocol');
        $useTls = (bool) $input->getOption('tls');

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

            $io->success(sprintf('Link established using protocol "%s". Entering read loop.', $protocol));

            $client->run();

            $io->warning('Connection closed by remote host.');
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
