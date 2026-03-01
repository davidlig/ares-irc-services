<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add last_topic_set_by_nick to registered_channels (nick who last set the topic; null if services).
 */
final class Version20260301210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_topic_set_by_nick to registered_channels.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_channels ADD COLUMN last_topic_set_by_nick VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_channels DROP COLUMN last_topic_set_by_nick');
    }
}
