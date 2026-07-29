<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\AstProgressionCalculator;
use App\State\Game;
use App\State\Player;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Replaying where a marker stood, from the only history the game keeps — what was bought, and when.
 *
 * Every test here runs assyria on the basic version, which puts it on the `standard` track: start on
 * square 0, Stone Age over 1-4, Early Bronze over 5-7, Middle Bronze over 8-10, Late Bronze over
 * 11-13 (config/game/ast.yaml). Squares are named rather than counted in the assertions, so a change
 * to those spans is meant to break these tests.
 *
 * Only two of the era's gates can be replayed at all — how many advances, of what cost — since
 * `cities` has no history; that omission and the ceiling that compensates for it are the subject of
 * the last three tests.
 */
final class AstProgressionCalculatorTest extends TestCase
{
    /**
     * Square 11 is the first Late Bronze Age one on this track, so a marker climbing at its maximum
     * rate of one square per turn is first offered it on turn 11 — the twelfth run of the loop is
     * needed only so that this turn is not the last, whose value is overwritten by the ceiling.
     */
    private const int LATE_BRONZE_GATE_TURN = 10;

    private AstProgressionCalculator $astProgressionCalculator;

    protected function setUp(): void
    {
        $this->astProgressionCalculator = new AstProgressionCalculator(
            GameConfig::astRegistry(),
            GameConfig::advanceRegistry(),
        );
    }

    /** The Stone Age gates on nothing at all, so an empire that bought nothing still leaves square 0. */
    #[Test]
    public function theFirstSquaresOfTheTrackAskForNothing(): void
    {
        $player = $this->createPlayer(astPosition: 3);

        $this->assertSame([1, 2, 3], $this->astProgressionCalculator->positionsPerTurn($player, [[], [], []]));
    }

    /**
     * A hand large enough for the Middle Bronze Age from turn 1 still buys one square a turn: the
     * marker climbs to 4 over four turns rather than jumping, the trailing 8 being the ceiling
     * writing the player's real position over the last point.
     */
    #[Test]
    public function theMarkerNeverClimbsMoreThanOneSquarePerTurn(): void
    {
        $player = $this->createPlayer(astPosition: 8);
        $hand = ['agriculture', 'architecture', 'calendar'];

        $this->assertSame([1, 2, 3, 4, 8], $this->astProgressionCalculator->positionsPerTurn($player, array_fill(0, 5, $hand)));
    }

    /**
     * The Late Bronze Age asks for three advances costing 100 or more, which is one condition and not
     * two: the cost bound says which advances the count may consider. A hand of three whose price
     * merely averages high is the case that tells the two readings apart, and it must not pass.
     *
     * @param list<string> $hand
     */
    #[Test]
    #[DataProvider('provideACostGatedEraOnlyCountsTheAdvancesThatCostEnoughCases')]
    public function aCostGatedEraOnlyCountsTheAdvancesThatCostEnough(array $hand, int $expectedPosition): void
    {
        $player = $this->createPlayer(astPosition: 11);

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

    /**
     * The replay is optimistic — it skips the `cities` gate it cannot read, and no calamity that
     * pushed a marker backwards leaves a trace — so where the player actually finished is a ceiling
     * on it, here holding a hand worth the Late Bronze Age down to square 2.
     */
    #[Test]
    public function theReplayedMarkerNeverClimbsPastWhereThePlayerActuallyFinished(): void
    {
        $player = $this->createPlayer(astPosition: 2);
        $hand = ['agriculture', 'architecture', 'calendar'];

        $this->assertSame([1, 2, 2, 2, 2], $this->astProgressionCalculator->positionsPerTurn($player, array_fill(0, 5, $hand)));
    }

    /** Both ends of the curve are facts; only its middle is deduced. Turn 3 deduces square 3 and is overruled. */
    #[Test]
    public function theLastPointIsThePlayersRealPositionRatherThanTheDeducedOne(): void
    {
        $player = $this->createPlayer(astPosition: 5);

        $this->assertSame([1, 2, 5], $this->astProgressionCalculator->positionsPerTurn($player, [[], [], []]));
    }

    /** The series is plotted against the turns of the game, so it owes one point to each of them. */
    #[Test]
    public function theSeriesHasExactlyOnePointPerTurnPlayed(): void
    {
        $player = $this->createPlayer(astPosition: 3);

        $this->assertCount(7, $this->astProgressionCalculator->positionsPerTurn($player, array_fill(0, 7, [])));
    }

    private function createPlayer(int $astPosition): Player
    {
        $player = new Player(new Game(), 'Bob', 'assyria');
        $player->astPosition = $astPosition;

        return $player;
    }
}
