<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add IRCop-only policy to registered channels';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('registered_channels')->addColumn('ircop_only', 'boolean', ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('registered_channels')->dropColumn('ircop_only');
    }
}
