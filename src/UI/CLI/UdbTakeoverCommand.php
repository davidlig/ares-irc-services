<?php

declare(strict_types=1);

namespace App\UI\CLI;

use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbOfflineTakeoverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

#[AsCommand(
    name: 'udb:takeover',
    description: 'Validate an offline UDB generation and replace the local authoritative store.',
)]
final class UdbTakeoverCommand extends Command
{
    public function __construct(private readonly UdbOfflineTakeoverInterface $takeover)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Offline UnrealIRCd UDB directory. The daemon must be stopped.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and calculate the replacement fingerprint without writing the store.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply without an interactive confirmation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = (string) $input->getArgument('directory');

        try {
            $dryRun = (bool) $input->getOption('dry-run');
            $fingerprint = $this->takeover->takeover($directory, false);
            if ($dryRun) {
                $io->success(sprintf('UDB takeover validation succeeded (candidate fingerprint %s).', $fingerprint));

                return Command::SUCCESS;
            }

            if (!$input->getOption('yes')) {
                $question = new ConfirmationQuestion('Apply this validated UDB takeover? [y/N] ', false);
                if (!new QuestionHelper()->ask($input, $output, $question)) {
                    $io->warning('UDB takeover cancelled.');

                    return Command::SUCCESS;
                }
            }
            $fingerprint = $this->takeover->takeover($directory);
            $io->success(sprintf('UDB takeover completed and approved (fingerprint %s).', $fingerprint));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
