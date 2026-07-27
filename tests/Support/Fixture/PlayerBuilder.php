<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixture;

use App\State\GameSession;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Object mother for Player. Replaces the twenty-eight private createPlayer() helpers the suite
 * used to carry under three mutually incompatible signatures — in-memory, persisted-with-its-own-
 * game, and persisted-into-a-given-game — which are now the in() and build()/persist() choices.
 *
 * Only the properties a fixture helper actually needed are exposed. Stats a test tunes as part of
 * its own Arrange block (census, treasury, cards, ships, astPosition) stay assigned at the call
 * site, where they read as the scenario rather than as construction.
 */
final class PlayerBuilder
{
    private ?GameSession $game = null;
    private ?string $empire = null;
    private ?int $cities = null;

    /** @var list<string> */
    private array $advances = [];

    private function __construct(private readonly string $name) {}

    public static function named(string $name): self
    {
        return new self($name);
    }

    /** Places the player in an existing game rather than in one of its own. */
    public function in(GameSession $game): self
    {
        $this->game = $game;

        return $this;
    }

    public function withEmpire(string $empire): self
    {
        $this->empire = $empire;

        return $this;
    }

    public function withCities(int $cities): self
    {
        $this->cities = $cities;

        return $this;
    }

    /** @param list<string> $keys */
    public function withAdvances(array $keys): self
    {
        $this->advances = $keys;

        return $this;
    }

    public function build(): Player
    {
        $player = new Player($this->game ?? GameBuilder::create()->build(), $this->name);

        if (null !== $this->empire) {
            $player->empire = $this->empire;
        }

        if (null !== $this->cities) {
            $player->cities = $this->cities;
        }

        if ([] !== $this->advances) {
            $player->ownAdvances($this->advances);
        }

        return $player;
    }

    /**
     * Persisting the game unconditionally is safe whether it came from in() or was built here:
     * Doctrine treats persist() on an already-managed entity as a no-op.
     */
    public function persist(EntityManagerInterface $entityManager): Player
    {
        $player = $this->build();

        $entityManager->persist($player->game);
        $entityManager->persist($player);
        $entityManager->flush();

        return $player;
    }
}
