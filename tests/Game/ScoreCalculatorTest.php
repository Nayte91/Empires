<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Advance;
use App\Entity\Game;
use App\Entity\Player;
use App\Game\Service\ScoreCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase
{
    #[Test]
    public function barePlayerScoresZero(): void
    {
        $player = new Player(new Game(), 'Bob');

        self::assertSame(0, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreSumsOwnedAdvancePoints(): void
    {
        $player = new Player(new Game(), 'Bob');
        $pottery = $this->makeAdvance('pottery', 3);
        $agriculture = $this->makeAdvance('agriculture', 4);

        self::assertSame(7, new ScoreCalculator()->scoreFor($player, [$pottery, $agriculture]));
    }

    #[Test]
    public function scoreCountsOneVictoryPointPerCity(): void
    {
        $player = new Player(new Game(), 'Bob');
        $player->cities = 5;

        self::assertSame(5, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreCountsFivePointsPerAstPosition(): void
    {
        $player = new Player(new Game(), 'Bob');
        $player->astPosition = 4;

        self::assertSame(20, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreCombinesAdvancesCitiesAndAstPosition(): void
    {
        $player = new Player(new Game(), 'Nayte');
        $player->cities = 7;
        $player->astPosition = 4;
        $advance = $this->makeAdvance('writing', 3);

        self::assertSame(30, new ScoreCalculator()->scoreFor($player, [$advance]));
    }

    #[Test]
    public function scoreCombinesCitiesAndAstPositionForKangoo(): void
    {
        $player = new Player(new Game(), 'Kangoo');
        $player->cities = 2;
        $player->astPosition = 5;

        self::assertSame(27, new ScoreCalculator()->scoreFor($player, []));
    }

    #[Test]
    public function scoreCombinesCitiesAndAstPositionForWalid(): void
    {
        $player = new Player(new Game(), 'Walid');
        $player->cities = 3;
        $player->astPosition = 5;

        self::assertSame(28, new ScoreCalculator()->scoreFor($player, []));
    }

    private function makeAdvance(string $key, int $points): Advance
    {
        return new Advance(
            key: $key,
            name: str_replace('_', ' ', $key),
            fileName: $key.'.webp',
            cost: 0,
            points: $points,
            categories: [],
            credits: [],
            mitigations: [],
            aggravations: [],
        );
    }
}
