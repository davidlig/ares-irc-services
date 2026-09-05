<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the optional Unreal operclass assigned to an OperServ role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oper_roles ADD operclass VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oper_roles DROP operclass');
    }
}
