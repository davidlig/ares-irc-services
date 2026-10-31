<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add uses and last_used_at to channel_akick, and verbose to registered_channels';
    }

    public function up(Schema $schema): void
    {
        $add = $this->platform instanceof SQLitePlatform ? ' ADD COLUMN ' : ' ADD ';

        $akickUses = $this->platform->getColumnDeclarationSQL('uses', ['name' => 'uses', 'typeName' => Types::INTEGER, 'default' => 0, 'notnull' => true]);
        $akickLastUsed = $this->platform->getColumnDeclarationSQL('last_used_at', ['name' => 'last_used_at', 'typeName' => Types::DATETIME_IMMUTABLE, 'notnull' => false]);
        $chanVerboseName = $this->platform->quoteSingleIdentifier('verbose');
        $chanVerbose = $this->platform->getColumnDeclarationSQL($chanVerboseName, ['name' => $chanVerboseName, 'typeName' => Types::BOOLEAN, 'default' => true, 'notnull' => true]);

        if ($this->platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE channel_akick ADD ' . $akickUses . ', ADD ' . $akickLastUsed);
            $this->addSql('ALTER TABLE registered_channels ADD ' . $chanVerbose);

            return;
        }

        $this->addSql('ALTER TABLE channel_akick' . $add . $akickUses);
        $this->addSql('ALTER TABLE channel_akick' . $add . $akickLastUsed);
        $this->addSql('ALTER TABLE registered_channels' . $add . $chanVerbose);
    }

    public function down(Schema $schema): void
    {
        $drop = $this->platform instanceof SQLitePlatform ? ' DROP COLUMN ' : ' DROP ';

        $this->addSql('ALTER TABLE channel_akick' . $drop . 'uses');
        $this->addSql('ALTER TABLE channel_akick' . $drop . 'last_used_at');
        $this->addSql('ALTER TABLE registered_channels' . $drop . $this->platform->quoteSingleIdentifier('verbose'));
    }
}
