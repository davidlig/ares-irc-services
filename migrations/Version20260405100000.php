<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add no_expire column to registered_nicks table for NickServ NOEXPIRE command';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('registered_nicks');
        $table->addColumn('no_expire', 'boolean', ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('registered_nicks');
        $table->dropColumn('no_expire');
    }
}
