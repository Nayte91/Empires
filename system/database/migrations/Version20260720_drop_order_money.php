<?php

declare(strict_types=1);

namespace System\database\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720_drop_order_money extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the money column from orders — payment is abstracted out of the order model entirely';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__orders AS SELECT id, turn, status, lines, total, validated_at, created_at, player_id FROM orders');
        $this->addSql('DROP TABLE orders');
        $this->addSql('CREATE TABLE orders (id BLOB NOT NULL, turn SMALLINT NOT NULL, status VARCHAR(255) NOT NULL, lines CLOB NOT NULL, total INTEGER DEFAULT NULL, validated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, player_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_E52FFDEE99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO orders (id, turn, status, lines, total, validated_at, created_at, player_id) SELECT id, turn, status, lines, total, validated_at, created_at, player_id FROM __temp__orders');
        $this->addSql('DROP TABLE __temp__orders');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_turn ON orders (player_id, turn)');
        $this->addSql('CREATE INDEX IDX_E52FFDEE99E6F5DF ON orders (player_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD COLUMN money INTEGER DEFAULT NULL');
    }
}
