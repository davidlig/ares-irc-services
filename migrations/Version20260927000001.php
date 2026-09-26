<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index registered nicknames for ordered WHOIP lookups';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('registered_nicks')->addIndex(['last_connect_ip', 'nickname_lower', 'id'], 'idx_registered_nicks_whoip');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('registered_nicks')->dropIndex('idx_registered_nicks_whoip');
    }
}
