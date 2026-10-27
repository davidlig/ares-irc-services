<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\In\Console;

use App\Bootstrap\Adapter\Out\Persistence\MigrationOwnedSchemaListener;
use Doctrine\ORM\Tools\Console\Command\ValidateSchemaCommand as DoctrineValidateSchemaCommand;
use Doctrine\ORM\Tools\ToolEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;

final class ValidateSchemaCommand extends DoctrineValidateSchemaCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->getEntityManager($input);
        $configuration = $em->getConnection()->getConfiguration();
        $previousFilter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn (string $name): bool => !in_array($name, [
            'doctrine_migration_versions', 'service_nick_reservations',
            'udb_staged_generations', 'udb_staged_mutations', 'udb_staged_records', 'udb_staged_transfers',
        ], true) && $previousFilter($name));
        $listener = new MigrationOwnedSchemaListener();
        $events = $em->getEventManager();
        $events->addEventListener([ToolEvents::postGenerateSchema], $listener);
        try {
            return parent::execute($input, $output);
        } finally {
            $events->removeEventListener([ToolEvents::postGenerateSchema], $listener);
            $configuration->setSchemaAssetsFilter($previousFilter);
        }
    }
}
