<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\State\Game;
use App\State\Player;
use App\Rules\Ruleset\Advance;
use App\Rules\ScoreCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase
{
    /**
     * @param array<string, int> $advancePoints advance key => its victory-point value
     */
    #[Test]
    #[DataProvider('provideScoreSumsAdvancePointsCitiesAndAstPositionCases')]
    public function scoreSumsAdvancePointsCitiesAndAstPosition(int $cities, int $astPosition, array $advancePoints, int $expectedScore): void
    {
        $player = new Player(new Game(), 'Bob', 'minoa');
        $player->cities = $cities;
        $player->astPosition = $astPosition;
        $advances = [];
        foreach ($advancePoints as $key => $points) {
            $advances[] = $this->makeAdvance($key, $points);
        }

        $this->assertSame($expectedScore, new ScoreCalculator()->scoreFor($player, $advances));
    }

    /** @return iterable<string, array{int, int, array<string, int>, int}> */
    public static function provideScoreSumsAdvancePointsCitiesAndAstPositionCases(): iterable
    {
        yield 'a bare player scores nothing' => [0, 0, [], 0];

        yield 'owned advances contribute their own points' => [0, 0, ['pottery' => 3, 'agriculture' => 4], 7];

        yield 'each city is worth one point' => [5, 0, [], 5];

        yield 'each A.S.T. position is worth five points' => [0, 4, [], 20];

        yield 'all three sources add up' => [7, 4, ['writing' => 3], 30];

        yield 'two cities and the fifth A.S.T. position' => [2, 5, [], 27];

        yield 'three cities and the fifth A.S.T. position' => [3, 5, [], 28];
    }

    /**
     * The player board's heading quotes this term on its own, so it has to answer on its own.
     *
     * @param array<string, int> $advancePoints advance key => its victory-point value
     */
    #[Test]
    #[DataProvider('provideAdvancePointsSumWhatTheOwnedAdvancesAreWorthCases')]
    public function advancePointsSumWhatTheOwnedAdvancesAreWorth(array $advancePoints, int $expectedPoints): void
    {
        $advances = [];
        foreach ($advancePoints as $key => $points) {
            $advances[] = $this->makeAdvance($key, $points);
        }

        $this->assertSame($expectedPoints, new ScoreCalculator()->advancePointsFor($advances));
    }

    /** @return iterable<string, array{array<string, int>, int}> */
    public static function provideAdvancePointsSumWhatTheOwnedAdvancesAreWorthCases(): iterable
    {
        yield 'owning nothing is worth nothing' => [[], 0];

        yield 'a single advance is worth its own points' => [['pottery' => 1], 1];

        yield 'several advances add up' => [['pottery' => 1, 'agriculture' => 3, 'writing' => 3], 7];

        yield 'an advance carrying no point contributes nothing' => [['cloth_making' => 0], 0];
    }

    /**
     * What the heading shows and what the score counts are the same arithmetic, not two copies of
     * it: with no city and the track at its start, the whole score *is* the advance term.
     */
    #[Test]
    public function theAdvanceTermIsExactlyWhatTheScoreCountsForAdvances(): void
    {
        $player = new Player(new Game(), 'Bob', 'minoa');
        $advances = [$this->makeAdvance('pottery', 1), $this->makeAdvance('agriculture', 3)];

        $calculator = new ScoreCalculator();

        $this->assertSame($calculator->scoreFor($player, $advances), $calculator->advancePointsFor($advances));
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
