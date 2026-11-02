<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add semantic automatic-mode preferences to registered nicknames';
    }

    public function up(Schema $schema): void
    {
        $declaration = $this->platform->getColumnDeclarationSQL('automatic_modes', ['name' => 'automatic_modes', 'typeName' => Types::JSON, 'notnull' => false]);
        $this->addSql('ALTER TABLE registered_nicks' . ($this->platform instanceof SQLitePlatform ? ' ADD COLUMN ' : ' ADD ') . $declaration);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_nicks' . ($this->platform instanceof SQLitePlatform ? ' DROP COLUMN ' : ' DROP ') . 'automatic_modes');
    }
}
