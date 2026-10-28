<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index registered channels for ordered keyset scans';
    }

    public function up(Schema $schema): void
    {
        $index = Index::editor()->setUnquotedName('idx_registered_channels_name_id')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['name', 'id'])->create();
        $this->addSql($this->platform->getCreateIndexSQL($index, 'registered_channels'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropIndexSQL('idx_registered_channels_name_id', 'registered_channels'));
    }
}
