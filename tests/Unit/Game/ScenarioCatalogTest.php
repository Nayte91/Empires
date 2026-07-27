<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\ScenarioCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenarioCatalogTest extends TestCase
{
    private ScenarioCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ScenarioCatalog(\dirname(__DIR__, 3).'/config/game/scenarios.yaml');
    }

    /** @param list<string> $expectedSlugs */
    #[Test]
    #[DataProvider('provideEmpiresForReturnsTheScenariosExactSlugsCases')]
    public function empiresForReturnsTheScenariosExactSlugs(int $playerCount, string $region, array $expectedSlugs): void
    {
        $this->assertSame($expectedSlugs, $this->catalog->empiresFor($playerCount, $region));
    }

    /** @return iterable<string, array{int, string, list<string>}> */
    public static function provideEmpiresForReturnsTheScenariosExactSlugsCases(): iterable
    {
        yield 'three players in the west' => [3, 'west', ['hatti', 'hellas', 'minoa']];

        yield 'nine players in the east' => [9, 'east', ['babylon', 'dravidia', 'indus', 'kushan', 'maurya', 'nubia', 'parthia', 'persia', 'saba']];
    }

    /** Ten players and up merge both regions into one flat, region-less list. */
    #[Test]
    #[DataProvider('provideEmpiresForReturnsAFlatListForACombinedScenarioCases')]
    public function empiresForReturnsAFlatListForACombinedScenario(int $playerCount): void
    {
        $empires = $this->catalog->empiresFor($playerCount, null);

        $this->assertCount($playerCount, $empires);
        $this->assertSame($empires, array_values(array_filter($empires, is_string(...))));
    }

    /** @return iterable<string, array{int}> */
    public static function provideEmpiresForReturnsAFlatListForACombinedScenarioCases(): iterable
    {
        yield 'ten players' => [10];

        yield 'twelve players' => [12];

        yield 'eighteen players' => [18];
    }

    #[Test]
    #[DataProvider('provideEmpiresForReturnsEmptyArrayForAnUnknownScenarioCases')]
    public function empiresForReturnsEmptyArrayForAnUnknownScenario(int $playerCount, ?string $region): void
    {
        $this->assertSame([], $this->catalog->empiresFor($playerCount, $region));
    }

    /** @return iterable<string, array{int, ?string}> */
    public static function provideEmpiresForReturnsEmptyArrayForAnUnknownScenarioCases(): iterable
    {
        yield 'player count above every scenario' => [19, null];

        yield 'region that does not exist' => [5, 'north'];

        yield 'no region below the ten-player combined threshold' => [3, null];

        yield 'player count and region that pair to nothing' => [2, 'west'];
    }

    #[Test]
    public function playerCountsContainsAllScenarioCounts(): void
    {
        $counts = $this->catalog->playerCounts();

        $this->assertSame(range(3, 18), $counts);
    }

    /** @param list<string> $expectedRegions */
    #[Test]
    #[DataProvider('provideRegionsForListsTheScenariosRegionsCases')]
    public function regionsForListsTheScenariosRegions(int $playerCount, array $expectedRegions): void
    {
        $this->assertSame($expectedRegions, $this->catalog->regionsFor($playerCount));
    }

    /** @return iterable<string, array{int, list<string>}> */
    public static function provideRegionsForListsTheScenariosRegionsCases(): iterable
    {
        yield 'a scenario split by region offers both' => [9, ['east', 'west']];

        yield 'a combined scenario offers none' => [10, []];
    }

    /** @param array<string, int> $expectedCredits */
    #[Test]
    #[DataProvider('provideStartingCreditsForReturnsTheScenariosCreditsCases')]
    public function startingCreditsForReturnsTheScenariosCredits(int $playerCount, array $expectedCredits): void
    {
        $this->assertSame($expectedCredits, $this->catalog->startingCreditsFor($playerCount));
    }

    /** @return iterable<string, array{int, array<string, int>}> */
    public static function provideStartingCreditsForReturnsTheScenariosCreditsCases(): iterable
    {
        yield 'three players get ten per category' => [3, ['art' => 10, 'civic' => 10, 'craft' => 10, 'religion' => 10, 'science' => 10]];

        yield 'four players get five per category' => [4, ['art' => 5, 'civic' => 5, 'craft' => 5, 'religion' => 5, 'science' => 5]];

        yield 'a scenario with no credits key grants none' => [9, []];
    }
}
