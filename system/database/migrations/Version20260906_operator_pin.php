<?php

declare(strict_types=1);

namespace System\database\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906_operator_pin extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the nullable operator PIN hash a game may be locked behind';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN operator_pin_hash VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN operator_pin_hash');
    }
}
