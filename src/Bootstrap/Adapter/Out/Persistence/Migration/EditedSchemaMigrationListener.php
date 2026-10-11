<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence\Migration;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Migrations\Event\MigrationsVersionEventArgs;
use Doctrine\Migrations\Events;
use LogicException;

/** Bridge immutable DBAL editors to the executor's per-version dry-run result. */
#[AsDoctrineListener(event: Events::onMigrationsVersionExecuted)]
final class EditedSchemaMigrationListener
{
    public function onMigrationsVersionExecuted(MigrationsVersionEventArgs $event): void
    {
        $plan = $event->getPlan();
        $migration = $plan->getMigration();
        if (!$migration instanceof EditedSchemaMigration) {
            return;
        }
        $target = $migration->getEditedSchema();
        $result = $plan->getResult();
        if (null === $target || null === $result) {
            throw new LogicException('Executed editor migration has no schema target or result.');
        }
        // Dispatched after execution, before DbalMigrator forwards this result
        // to the next version. Never mutate the executor's original schema.
        $result->setToSchema(clone $target);
    }
}
