<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\ScoreCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;

final class ScoreCalculatorTest extends TestCase
{
    /**
     * @param array<string, int> $advancePoints advance key => its victory-point value
     */
    #[Test]
    #[DataProvider('provideScoreSumsAdvancePointsCitiesAndAstPositionCases')]
    public function scoreSumsAdvancePointsCitiesAndAstPosition(int $cities, int $astPosition, array $advancePoints, int $expectedScore): void
    {
        $player = PlayerBuilder::named('Bob')->withCities($cities)->withAstPosition($astPosition)->build();
        $advances = [];
        foreach ($advancePoints as $key => $points) {
            $advances[] = GameConfig::advance($key, points: $points);
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

    /** @param array<string, int> $advancePoints advance key => its victory-point value */
    #[Test]
    #[DataProvider('provideAdvancePointsSumWhatTheOwnedAdvancesAreWorthCases')]
    public function advancePointsSumWhatTheOwnedAdvancesAreWorth(array $advancePoints, int $expectedPoints): void
    {
        $advances = [];
        foreach ($advancePoints as $key => $points) {
            $advances[] = GameConfig::advance($key, points: $points);
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

    #[Test]
    public function theAdvanceTermIsExactlyWhatTheScoreCountsForAdvances(): void
    {
        $playerWithNoCityAtTheTrackStart = PlayerBuilder::named('Bob')->build();
        $advances = [GameConfig::advance('pottery', points: 1), GameConfig::advance('agriculture', points: 3)];

        $calculator = new ScoreCalculator();

        $this->assertSame($calculator->scoreFor($playerWithNoCityAtTheTrackStart, $advances), $calculator->advancePointsFor($advances));
    }
}
