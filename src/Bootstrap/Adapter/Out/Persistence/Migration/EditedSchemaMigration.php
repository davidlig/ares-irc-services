<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence\Migration;

use Doctrine\DBAL\Schema\Schema;

/** A queued-DDL migration supplies its detached target to the migration planner. */
interface EditedSchemaMigration
{
    public function getEditedSchema(): ?Schema;
}
