<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require explicit approval of the complete UDB dataset before services may propagate it';
    }

    public function up(Schema $schema): void
    {
        $state = $schema->createTable('udb_authority_state');
        $state->addColumn('id', 'integer', ['autoincrement' => true]);
        $state->addColumn('approved', 'boolean');
        $state->addColumn('approved_at', 'datetimetz_immutable', ['notnull' => false]);
        $state->addColumn('fingerprint', 'string', ['length' => 64, 'notnull' => false]);
        $state->setPrimaryKey(['id']);
    }

    public function postUp(Schema $schema): void
    {
        // Schema-diff SQL runs AFTER addSql(), so the seed row must be
        // inserted from postUp where the table already exists. The migration
        // is frozen at that point, hence the direct connection insert.
        $this->connection->insert('udb_authority_state', ['approved' => 0]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('udb_authority_state');
    }
}
