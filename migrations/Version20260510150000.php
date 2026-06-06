<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shown counter to OperServ MOTD entries';
    }

    public function up(Schema $schema): void
    {
        $motd = $schema->getTable('motd');
        $motd->addColumn('shown_count', 'integer', ['default' => 0]);
    }

    public function down(Schema $schema): void
    {
        $motd = $schema->getTable('motd');
        $motd->dropColumn('shown_count');
    }
}
