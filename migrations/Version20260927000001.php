<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
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
        $index = Index::editor()->setUnquotedName('idx_registered_nicks_whoip')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['last_connect_ip', 'nickname_lower', 'id'])->create();
        $this->addSql($this->platform->getCreateIndexSQL($index, 'registered_nicks'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropIndexSQL('idx_registered_nicks_whoip', 'registered_nicks'));
    }
}
