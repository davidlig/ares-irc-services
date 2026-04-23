<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328184511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make channel_akick.creator_nick_id nullable to support clearing creator on nick drop';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('channel_akick');
        $table->modifyColumn('creator_nick_id', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('channel_akick');
        $table->modifyColumn('creator_nick_id', ['notnull' => true]);
    }
}
