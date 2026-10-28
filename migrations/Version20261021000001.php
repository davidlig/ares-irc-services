<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable confirmed NickServ rescue credential hashes';
    }

    public function up(Schema $schema): void
    {
        $column = $this->platform->getColumnDeclarationSQL('token', ['name' => 'token', 'typeName' => Types::STRING, 'length' => 64, 'default' => null, 'notnull' => false]);
        $add = $this->platform instanceof SQLitePlatform ? ' ADD COLUMN ' : ' ADD ';
        $this->addSql('ALTER TABLE registered_nicks' . $add . $column);
    }

    public function down(Schema $schema): void
    {
        // Native DROP COLUMN preserves migration-owned expression/search indexes.
        $this->addSql('ALTER TABLE registered_nicks DROP COLUMN token');
    }
}
