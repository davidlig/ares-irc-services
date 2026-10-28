<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

use function implode;

final class Version20261026000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ChanServ registration approval lifecycle metadata';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            'registration_rejection_reason' => ['typeName' => Types::TEXT, 'notnull' => false],
            'registration_rejected_by_account_id' => ['typeName' => Types::INTEGER, 'notnull' => false],
            'registration_rejected_by_nickname' => ['typeName' => Types::STRING, 'length' => 64, 'notnull' => false],
            'registration_rejected_at' => ['typeName' => Types::DATETIME_IMMUTABLE, 'notnull' => false],
            'registration_restricted_until' => ['typeName' => Types::DATETIME_IMMUTABLE, 'notnull' => false],
        ];
        $add = $this->platform instanceof SQLitePlatform ? 'ADD COLUMN ' : 'ADD ';
        $declarations = [];
        foreach ($columns as $name => $definition) {
            $declarations[] = $add . $this->platform->getColumnDeclarationSQL($name, ['name' => $name] + $definition);
        }
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE registered_channels ' . implode(', ', $declarations));

            return;
        }
        foreach ($declarations as $declaration) {
            $this->addSql('ALTER TABLE registered_channels ' . $declaration);
        }
    }

    public function down(Schema $schema): void
    {
        // Native drops retain SQLite's migration-owned COLLATE NOCASE index.
        foreach ([
            'registration_rejection_reason',
            'registration_rejected_by_account_id',
            'registration_rejected_by_nickname',
            'registration_rejected_at',
            'registration_restricted_until',
        ] as $column) {
            $this->addSql('ALTER TABLE registered_channels DROP COLUMN ' . $column);
        }
    }
}
