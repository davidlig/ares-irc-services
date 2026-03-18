<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Insert default protected oper roles.
 */
final class Version20260318194400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert default protected oper roles: ADMIN, OPER, PREOPER';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO oper_roles (name, description, protected) VALUES ('ADMIN', 'Administrator role with full access', 1)");
        $this->addSql("INSERT INTO oper_roles (name, description, protected) VALUES ('OPER', 'Operator role with standard access', 1)");
        $this->addSql("INSERT INTO oper_roles (name, description, protected) VALUES ('PREOPER', 'Pre-operator role with limited access', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM oper_roles WHERE name IN ('ADMIN', 'OPER', 'PREOPER')");
    }
}
