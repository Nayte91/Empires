<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\ScenarioCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenarioCatalogTest extends TestCase
{
    private ScenarioCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ScenarioCatalog(\dirname(__DIR__, 2).'/config/game/scenarios.yaml');
    }

    #[Test]
    public function empiresForReturnsExactSlugsForThreeWest(): void
    {
        $this->assertSame(['hatti', 'hellas', 'minoa'], $this->catalog->empiresFor(3, 'west'));
    }

    #[Test]
    public function empiresForReturnsExactSlugsForNineEast(): void
    {
        $this->assertSame(['babylon', 'dravidia', 'indus', 'kushan', 'maurya', 'nubia', 'parthia', 'persia', 'saba'], $this->catalog->empiresFor(9, 'east'));
    }

    #[Test]
    public function empiresForReturnsFlatListForCombinedTenPlayers(): void
    {
        $empires = $this->catalog->empiresFor(10, null);

        $this->assertCount(10, $empires);
        $this->assertSame($empires, array_values(array_filter($empires, is_string(...))));
    }

    #[Test]
    public function empiresForReturnsFlatListForCombinedTwelvePlayers(): void
    {
        $empires = $this->catalog->empiresFor(12, null);

        $this->assertCount(12, $empires);
        $this->assertSame($empires, array_values(array_filter($empires, is_string(...))));
    }

    #[Test]
    public function empiresForReturnsFlatListForCombinedEighteenPlayers(): void
    {
        $empires = $this->catalog->empiresFor(18, null);

        $this->assertCount(18, $empires);
        $this->assertSame($empires, array_values(array_filter($empires, is_string(...))));
    }

    #[Test]
    public function empiresForReturnsEmptyArrayForUnknownPlayerCount(): void
    {
        $this->assertSame([], $this->catalog->empiresFor(19, null));
    }

    #[Test]
    public function empiresForReturnsEmptyArrayForUnknownRegion(): void
    {
        $this->assertSame([], $this->catalog->empiresFor(5, 'north'));
    }

    #[Test]
    public function empiresForReturnsEmptyArrayForNullRegionBelowTenPlayers(): void
    {
        $this->assertSame([], $this->catalog->empiresFor(3, null));
    }

    #[Test]
    public function empiresForReturnsEmptyArrayForUnknownCombinationBelowTenPlayers(): void
    {
        $this->assertSame([], $this->catalog->empiresFor(2, 'west'));
    }

    #[Test]
    public function playerCountsContainsAllScenarioCounts(): void
    {
        $counts = $this->catalog->playerCounts();

        $this->assertSame(range(3, 18), $counts);
    }

    #[Test]
    public function regionsForReturnsEastAndWestWhenSplitByRegion(): void
    {
        $this->assertSame(['east', 'west'], $this->catalog->regionsFor(9));
    }

    #[Test]
    public function regionsForReturnsEmptyArrayForCombinedPlayerCount(): void
    {
        $this->assertSame([], $this->catalog->regionsFor(10));
    }

    #[Test]
    public function startingCreditsForReturnsTenPerCategoryForThreePlayers(): void
    {
        $this->assertSame(['art' => 10, 'civic' => 10, 'craft' => 10, 'religion' => 10, 'science' => 10], $this->catalog->startingCreditsFor(3));
    }

    #[Test]
    public function startingCreditsForReturnsFivePerCategoryForFourPlayers(): void
    {
        $this->assertSame(['art' => 5, 'civic' => 5, 'craft' => 5, 'religion' => 5, 'science' => 5], $this->catalog->startingCreditsFor(4));
    }

    #[Test]
    public function startingCreditsForReturnsEmptyArrayWhenScenarioHasNoCredits(): void
    {
        $this->assertSame([], $this->catalog->startingCreditsFor(9));
    }
}
