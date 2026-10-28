<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index registered channels for filtered maintenance scans';
    }

    public function up(Schema $schema): void
    {
        $index = Index::editor()->setUnquotedName('idx_registered_channels_status_suspended_until')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['status', 'suspended_until'])->create();
        $this->addSql($this->platform->getCreateIndexSQL($index, 'registered_channels'));
        $index = Index::editor()->setUnquotedName('idx_registered_channels_inactivity')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['no_expire', 'last_used_at', 'created_at'])->create();
        $this->addSql($this->platform->getCreateIndexSQL($index, 'registered_channels'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropIndexSQL('idx_registered_channels_inactivity', 'registered_channels'));
        $this->addSql($this->platform->getDropIndexSQL('idx_registered_channels_status_suspended_until', 'registered_channels'));
    }
}
