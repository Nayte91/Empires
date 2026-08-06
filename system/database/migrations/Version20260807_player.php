<?php

declare(strict_types=1);

namespace System\database\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807_player extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Narrow player name to the 30-character rule the entity declares';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__player AS SELECT id, slug, empire, advances, credit_ledger, cities, census, treasury, ships, cards, ast_position, name, game_id FROM player');
        $this->addSql('DROP TABLE player');
        $this->addSql('CREATE TABLE player (id BLOB NOT NULL, slug VARCHAR(64) NOT NULL, empire VARCHAR(30) NOT NULL, advances CLOB NOT NULL, credit_ledger CLOB DEFAULT \'[]\' NOT NULL, cities SMALLINT DEFAULT 0 NOT NULL, census SMALLINT DEFAULT 1 NOT NULL, treasury SMALLINT DEFAULT 0 NOT NULL, ships SMALLINT DEFAULT 0 NOT NULL, cards SMALLINT DEFAULT 0 NOT NULL, ast_position SMALLINT DEFAULT 0 NOT NULL, name VARCHAR(30) NOT NULL, game_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_98197A65E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO player (id, slug, empire, advances, credit_ledger, cities, census, treasury, ships, cards, ast_position, name, game_id) SELECT id, slug, empire, advances, credit_ledger, cities, census, treasury, ships, cards, ast_position, name, game_id FROM __temp__player');
        $this->addSql('DROP TABLE __temp__player');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_game_slug ON player (game_id, slug)');
        $this->addSql('CREATE INDEX IDX_98197A65E48FD905 ON player (game_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__player AS SELECT id, slug, advances, credit_ledger, cities, census, treasury, ships, cards, ast_position, name, empire, game_id FROM player');
        $this->addSql('DROP TABLE player');
        $this->addSql('CREATE TABLE player (id BLOB NOT NULL, slug VARCHAR(64) NOT NULL, advances CLOB NOT NULL, credit_ledger CLOB DEFAULT \'[]\' NOT NULL, cities SMALLINT DEFAULT 0 NOT NULL, census SMALLINT DEFAULT 1 NOT NULL, treasury SMALLINT DEFAULT 0 NOT NULL, ships SMALLINT DEFAULT 0 NOT NULL, cards SMALLINT DEFAULT 0 NOT NULL, ast_position SMALLINT DEFAULT 0 NOT NULL, name VARCHAR(50) NOT NULL, empire VARCHAR(30) NOT NULL, game_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_98197A65E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO player (id, slug, advances, credit_ledger, cities, census, treasury, ships, cards, ast_position, name, empire, game_id) SELECT id, slug, advances, credit_ledger, cities, census, treasury, ships, cards, ast_position, name, empire, game_id FROM __temp__player');
        $this->addSql('DROP TABLE __temp__player');
        $this->addSql('CREATE INDEX IDX_98197A65E48FD905 ON player (game_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_game_slug ON player (game_id, slug)');
    }
}
