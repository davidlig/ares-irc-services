<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add private INFO policy to registered channels';
    }

    public function up(Schema $schema): void
    {
        $column = $this->platform->getColumnDeclarationSQL('private', ['name' => 'private', 'typeName' => Types::BOOLEAN, 'default' => false, 'notnull' => true]);
        $add = $this->platform instanceof SQLitePlatform ? ' ADD COLUMN ' : ' ADD ';
        $this->addSql('ALTER TABLE registered_channels' . $add . $column);
    }

    public function down(Schema $schema): void
    {
        $drop = $this->platform instanceof SQLitePlatform ? ' DROP COLUMN ' : ' DROP ';
        $this->addSql('ALTER TABLE registered_channels' . $drop . 'private');
    }
}
