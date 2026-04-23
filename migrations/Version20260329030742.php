<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329030742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add forced_vhost_pattern column to oper_roles table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('oper_roles');
        $table->addColumn('forced_vhost_pattern', 'string', ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('oper_roles');
        $table->dropColumn('forced_vhost_pattern');
    }
}
