<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create udb_block_states table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('udb_block_states');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('block', Types::STRING, ['length' => 1]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64]);
        $table->addColumn('record_count', Types::INTEGER, ['default' => 0]);
        $table->addColumn('modified_at', Types::DATETIMETZ_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueConstraint(['block'], 'uniq_udb_block_state_block');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('udb_block_states');
    }
}
