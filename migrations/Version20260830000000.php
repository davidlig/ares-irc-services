<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the authoritative UDB store (all six blocks) and per-block state markers';
    }

    public function up(Schema $schema): void
    {
        $records = $schema->createTable('udb_records');
        $records->addColumn('id', 'integer', ['autoincrement' => true]);
        $records->addColumn('block', 'string', ['length' => 1]);
        $records->addColumn('path', 'string', ['length' => 8192]);
        $records->addColumn('identity_path', 'string', ['length' => 8192]);
        $records->addColumn('value', 'text');
        $records->addColumn('updated_at', 'datetimetz_immutable');
        $records->setPrimaryKey(['id']);
        $records->addUniqueConstraint(['block', 'identity_path'], 'uniq_udb_record_block_identity');
        $records->addIndex(['block'], 'idx_udb_record_block');

        $states = $schema->createTable('udb_block_states');
        $states->addColumn('id', 'integer', ['autoincrement' => true]);
        $states->addColumn('block', 'string', ['length' => 1]);
        $states->addColumn('checksum', 'string', ['length' => 8]);
        $states->addColumn('synced_at', 'datetimetz_immutable');
        $states->setPrimaryKey(['id']);
        $states->addUniqueConstraint(['block'], 'uniq_udb_block_state_block');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('udb_block_states');
        $schema->dropTable('udb_records');
    }
}
