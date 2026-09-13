<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create udb_records table with binary identity storage';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('udb_records');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('block', Types::STRING, ['length' => 1]);
        $table->addColumn('path', Types::STRING, ['length' => 8192]);
        $table->addColumn('identity_path', Types::BINARY, ['length' => 8192]);
        $table->addColumn('identity_hash', Types::BINARY, ['length' => 32]);
        $table->addColumn('value', Types::TEXT);
        $table->addColumn('updated_at', Types::DATETIMETZ_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueConstraint(['block', 'identity_hash'], 'uniq_udb_record_block_identity');
        $table->addIndex(['block'], 'idx_udb_record_block');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('udb_records');
    }
}
