<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412014104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add forbidden_reason column and forbidden status to registered_channels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_channels ADD forbidden_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_channels DROP forbidden_reason');
    }
}
