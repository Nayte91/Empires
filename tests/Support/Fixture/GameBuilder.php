<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixture;

use App\Entity\GameSession;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Object mother for GameSession. Replaces the eight near-identical private createGame() helpers
 * the suite used to carry, so a test states the one property its scenario turns on and inherits
 * the entity's own defaults for everything else.
 *
 * build() returns an unmanaged instance for tests that never touch the database; persist() writes
 * it and flushes, for tests that do. DAMA rolls the write back, so neither path needs cleanup.
 */
final class GameBuilder
{
    private ?string $slug = null;
    private ?int $playerCount = null;
    private ?int $currentTurn = null;

    public static function create(): self
    {
        return new self();
    }

    public function withSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function withPlayerCount(int $playerCount): self
    {
        $this->playerCount = $playerCount;

        return $this;
    }

    public function withCurrentTurn(int $currentTurn): self
    {
        $this->currentTurn = $currentTurn;

        return $this;
    }

    public function build(): GameSession
    {
        $game = new GameSession($this->slug);

        if (null !== $this->playerCount) {
            $game->playerCount = $this->playerCount;
        }

        if (null !== $this->currentTurn) {
            $game->currentTurn = $this->currentTurn;
        }

        return $game;
    }

    public function persist(EntityManagerInterface $entityManager): GameSession
    {
        $game = $this->build();

        $entityManager->persist($game);
        $entityManager->flush();

        return $game;
    }
}
