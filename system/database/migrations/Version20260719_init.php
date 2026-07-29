<?php

declare(strict_types=1);

namespace System\database\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719_init extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game (id BLOB NOT NULL, slug VARCHAR(64) NOT NULL, current_turn SMALLINT NOT NULL, region VARCHAR(16) DEFAULT NULL, ast_version VARCHAR(255) NOT NULL, player_count SMALLINT NOT NULL, finished_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232B318C989D9B62 ON game (slug)');
        $this->addSql("CREATE TABLE player (id BLOB NOT NULL, slug VARCHAR(64) NOT NULL, empire VARCHAR(30) NOT NULL, advances CLOB NOT NULL, credit_ledger CLOB DEFAULT '[]' NOT NULL, cities SMALLINT DEFAULT 0 NOT NULL, census SMALLINT DEFAULT 1 NOT NULL, treasury SMALLINT DEFAULT 0 NOT NULL, ships SMALLINT DEFAULT 0 NOT NULL, cards SMALLINT DEFAULT 0 NOT NULL, ast_position SMALLINT DEFAULT 0 NOT NULL, name VARCHAR(50) NOT NULL, game_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_98197A65E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX IDX_98197A65E48FD905 ON player (game_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_game_slug ON player (game_id, slug)');
        $this->addSql('CREATE TABLE orders (id BLOB NOT NULL, status VARCHAR(255) NOT NULL, lines CLOB NOT NULL, total INTEGER DEFAULT NULL, validated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, turn SMALLINT NOT NULL, player_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_E52FFDEE99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E52FFDEE99E6F5DF ON orders (player_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_turn ON orders (player_id, turn)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE player');
        $this->addSql('DROP TABLE game');
    }
}
