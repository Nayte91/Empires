<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixture;

use App\State\Game;
use App\State\Region;
use Doctrine\ORM\EntityManagerInterface;

final class GameBuilder
{
    private ?string $slug = null;
    private ?int $playerCount = null;
    private ?int $currentTurn = null;
    private bool $regionGiven = false;
    private ?Region $region = null;

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

    public function withRegion(?Region $region): self
    {
        $this->regionGiven = true;
        $this->region = $region;

        return $this;
    }

    public function build(): Game
    {
        $game = new Game($this->slug);

        if (null !== $this->playerCount) {
            $game->playerCount = $this->playerCount;
        }

        if (null !== $this->currentTurn) {
            $game->currentTurn = $this->currentTurn;
        }

        if ($this->regionGiven) {
            $game->region = $this->region;
        }

        return $game;
    }

    public function persist(EntityManagerInterface $entityManager): Game
    {
        $game = $this->build();

        $entityManager->persist($game);
        $entityManager->flush();

        return $game;
    }
}
