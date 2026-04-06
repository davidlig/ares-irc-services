<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_connect_ip and last_connect_host columns to registered_nicks';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('registered_nicks');
        $table->addColumn('last_connect_ip', 'string', ['length' => 45, 'notnull' => false]);
        $table->addColumn('last_connect_host', 'string', ['length' => 256, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('registered_nicks');
        $table->dropColumn('last_connect_host');
        $table->dropColumn('last_connect_ip');
    }
}
