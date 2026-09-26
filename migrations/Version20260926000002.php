<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add private INFO policy to registered channels';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('registered_channels')->addColumn('private', 'boolean', ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('registered_channels')->dropColumn('private');
    }
}
