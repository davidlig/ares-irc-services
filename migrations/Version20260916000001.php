<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track Ares-owned service nickname reservations per IRC protocol';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('service_nick_reservations');
        $table->addColumn('protocol', Types::STRING, ['length' => 32]);
        $table->addColumn('nickname_lower', Types::STRING, ['length' => 64]);
        $table->addColumn('nickname', Types::STRING, ['length' => 64]);
        $table->setPrimaryKey(['protocol', 'nickname_lower']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('service_nick_reservations');
    }
}
