<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Dto\Advance;
use App\Game\Service\ScoreCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase
{
    #[Test]
    public function barePlayerScoresZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $this->assertSame(0, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreSumsOwnedAdvancePoints(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $pottery = $this->makeAdvance('pottery', 3);
        $agriculture = $this->makeAdvance('agriculture', 4);

        $this->assertSame(7, new ScoreCalculator()->scoreFor($player, [$pottery, $agriculture]));
    }

    #[Test]
    public function scoreCountsOneVictoryPointPerCity(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 5;

        $this->assertSame(5, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreCountsFivePointsPerAstPosition(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->astPosition = 4;

        $this->assertSame(20, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreCombinesAdvancesCitiesAndAstPosition(): void
    {
        $player = new Player(new GameSession(), 'Nayte');
        $player->cities = 7;
        $player->astPosition = 4;
        $advance = $this->makeAdvance('writing', 3);

        $this->assertSame(30, new ScoreCalculator()->scoreFor($player, [$advance]));
    }

    #[Test]
    public function scoreCombinesCitiesAndAstPositionForKangoo(): void
    {
        $player = new Player(new GameSession(), 'Kangoo');
        $player->cities = 2;
        $player->astPosition = 5;

        $this->assertSame(27, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreCombinesCitiesAndAstPositionForWalid(): void
    {
        $player = new Player(new GameSession(), 'Walid');
        $player->cities = 3;
        $player->astPosition = 5;

        $this->assertSame(28, new ScoreCalculator()->scoreFor($player, []));
    }

    private function makeAdvance(string $key, int $points): Advance
    {
        return new Advance(
            key: $key,
            name: str_replace('_', ' ', $key),
            fileName: $key.'.webp',
            cost: 0,
            points: $points,
            facets: [],
            credits: [],
            mitigations: [],
            aggravations: [],
        );
    }
}
