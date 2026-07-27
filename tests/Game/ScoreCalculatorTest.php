<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Dto\Advance;
use App\Game\Service\ScoreCalculator;
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
        $player = new Player(new GameSession(), 'Bob');
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
