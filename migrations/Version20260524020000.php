<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recoverable manual DROP state for registered nicks and channels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_nicks ADD pending_deletion_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_status_pending_deletion ON registered_nicks (status, pending_deletion_at)');
        $this->addSql('ALTER TABLE registered_channels ADD pending_deletion_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_registered_channels_pending_deletion ON registered_channels (status, pending_deletion_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_status_pending_deletion');
        $this->addSql('ALTER TABLE registered_nicks DROP pending_deletion_at');
        $this->addSql('DROP INDEX idx_registered_channels_pending_deletion');
        $this->addSql('ALTER TABLE registered_channels DROP pending_deletion_at');
    }
}
