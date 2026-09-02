<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixture;

use App\State\CreditEntry;
use App\State\Game;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;

final class PlayerBuilder
{
    private ?Game $game = null;
    private ?int $cities = null;
    private ?int $census = null;
    private ?int $treasury = null;
    private ?int $cards = null;
    private ?int $ships = null;
    private ?int $astPosition = null;

    private string $empire = 'minoa';

    /** @var list<string> */
    private array $advances = [];

    /** @var list<CreditEntry> */
    private array $credits = [];

    private function __construct(private readonly string $name) {}

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function in(Game $game): self
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

    public function withCensus(int $census): self
    {
        $this->census = $census;

        return $this;
    }

    public function withTreasury(int $treasury): self
    {
        $this->treasury = $treasury;

        return $this;
    }

    public function withCards(int $cards): self
    {
        $this->cards = $cards;

        return $this;
    }

    public function withShips(int $ships): self
    {
        $this->ships = $ships;

        return $this;
    }

    public function withAstPosition(int $astPosition): self
    {
        $this->astPosition = $astPosition;

        return $this;
    }

    /** In the order they were posted: the walk that reads them back is order-sensitive. */
    public function withCredits(CreditEntry ...$entries): self
    {
        $this->credits = array_values($entries);

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
        $player = new Player($this->game ?? GameBuilder::create()->build(), $this->name, $this->empire);

        if (null !== $this->cities) {
            $player->cities = $this->cities;
        }

        if (null !== $this->census) {
            $player->census = $this->census;
        }

        if (null !== $this->treasury) {
            $player->treasury = $this->treasury;
        }

        if (null !== $this->cards) {
            $player->cards = $this->cards;
        }

        if (null !== $this->ships) {
            $player->ships = $this->ships;
        }

        if (null !== $this->astPosition) {
            $player->astPosition = $this->astPosition;
        }

        if ([] !== $this->advances) {
            $player->ownAdvances($this->advances);
        }

        foreach ($this->credits as $entry) {
            $player->postCredit($entry);
        }

        return $player;
    }

    /** persist() on an already-managed entity is a no-op, so persisting the game unconditionally is safe. */
    public function persist(EntityManagerInterface $entityManager): Player
    {
        $player = $this->build();

        $entityManager->persist($player->game);
        $entityManager->persist($player);
        $entityManager->flush();

        return $player;
    }
}
