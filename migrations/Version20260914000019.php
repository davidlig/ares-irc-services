<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create udb_authority_state table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('udb_authority_state');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('approved', Types::BOOLEAN);
        $table->addColumn('approved_at', Types::DATETIMETZ_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('fingerprint', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('protocol_revision', Types::STRING, ['length' => 40, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->insert('udb_authority_state', ['approved' => false], ['approved' => Types::BOOLEAN]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('udb_authority_state');
    }
}
