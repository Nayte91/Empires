<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\AstProgressionCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\PlayerBuilder;

final class AstProgressionCalculatorTest extends TestCase
{
    private const string STANDARD_TRACK_EMPIRE = 'assyria';

    /** Square 11 is first offered on turn 11; the twelfth run only keeps this off the last turn, whose value the ceiling overwrites. */
    private const int LATE_BRONZE_GATE_TURN = 10;

    private AstProgressionCalculator $astProgressionCalculator;

    protected function setUp(): void
    {
        $this->astProgressionCalculator = new AstProgressionCalculator(
            GameConfig::astRegistry(),
            GameConfig::advanceRegistry(),
        );
    }

    #[Test]
    public function theFirstSquaresOfTheTrackAskForNothing(): void
    {
        $player = PlayerBuilder::named('Bob')->withEmpire(self::STANDARD_TRACK_EMPIRE)->withAstPosition(3)->build();

        $this->assertSame([1, 2, 3], $this->astProgressionCalculator->positionsPerTurn($player, [[], [], []]));
    }

    /** The trailing 8 is the ceiling writing the player's real position over the last point. */
    #[Test]
    public function theMarkerNeverClimbsMoreThanOneSquarePerTurn(): void
    {
        $player = PlayerBuilder::named('Bob')->withEmpire(self::STANDARD_TRACK_EMPIRE)->withAstPosition(8)->build();
        $hand = ['agriculture', 'architecture', 'calendar'];

        $this->assertSame([1, 2, 3, 4, 8], $this->astProgressionCalculator->positionsPerTurn($player, array_fill(0, 5, $hand)));
    }

    /** @param list<string> $hand */
    #[Test]
    #[DataProvider('provideACostGatedEraOnlyCountsTheAdvancesThatCostEnoughCases')]
    public function aCostGatedEraOnlyCountsTheAdvancesThatCostEnough(array $hand, int $expectedPosition): void
    {
        $player = PlayerBuilder::named('Bob')->withEmpire(self::STANDARD_TRACK_EMPIRE)->withAstPosition(11)->build();

        $positions = $this->astProgressionCalculator->positionsPerTurn($player, array_fill(0, 12, $hand));

        $this->assertSame($expectedPosition, $positions[self::LATE_BRONZE_GATE_TURN]);
    }

    /** @return iterable<string, array{list<string>, int}> */
    public static function provideACostGatedEraOnlyCountsTheAdvancesThatCostEnoughCases(): iterable
    {
        yield 'three advances at 50 each are three advances the gate may not count' => [['cloth_making', 'mysticism', 'sculpture'], 10];

        yield 'three advances of which only one costs enough count as one' => [['cloth_making', 'mysticism', 'agriculture'], 10];

        yield 'three advances at 120 and above are the three the gate asks for' => [['agriculture', 'architecture', 'calendar'], 11];
    }

    #[Test]
    public function theReplayedMarkerNeverClimbsPastWhereThePlayerActuallyFinished(): void
    {
        $player = PlayerBuilder::named('Bob')->withEmpire(self::STANDARD_TRACK_EMPIRE)->withAstPosition(2)->build();
        $hand = ['agriculture', 'architecture', 'calendar'];

        $this->assertSame([1, 2, 2, 2, 2], $this->astProgressionCalculator->positionsPerTurn($player, array_fill(0, 5, $hand)));
    }

    /** Turn 3 deduces square 3 and is overruled by the player's real position. */
    #[Test]
    public function theLastPointIsThePlayersRealPositionRatherThanTheDeducedOne(): void
    {
        $player = PlayerBuilder::named('Bob')->withEmpire(self::STANDARD_TRACK_EMPIRE)->withAstPosition(5)->build();

        $this->assertSame([1, 2, 5], $this->astProgressionCalculator->positionsPerTurn($player, [[], [], []]));
    }

    #[Test]
    public function theSeriesHasExactlyOnePointPerTurnPlayed(): void
    {
        $player = PlayerBuilder::named('Bob')->withEmpire(self::STANDARD_TRACK_EMPIRE)->withAstPosition(3)->build();

        $this->assertCount(7, $this->astProgressionCalculator->positionsPerTurn($player, array_fill(0, 7, [])));
    }
}
