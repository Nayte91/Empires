<?php

declare(strict_types=1);

namespace System\database\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718_game_session extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the game table to game_session, resolving the naming collision with the App\Game bounded context';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game RENAME TO game_session');
        $this->addSql('DROP INDEX UNIQ_232B318C989D9B62');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4586AAFB989D9B62 ON game_session (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_session RENAME TO game');
        $this->addSql('DROP INDEX UNIQ_4586AAFB989D9B62');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232B318C989D9B62 ON game (slug)');
    }
}
