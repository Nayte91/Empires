<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Ruleset;

use App\Rules\Ruleset\Scenario;
use App\Rules\Ruleset\ScenarioRegistry;
use App\State\Region;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenarioRegistryTest extends TestCase
{
    private ScenarioRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ScenarioRegistry(\dirname(__DIR__, 4).'/config/game/scenarios.yaml');
    }

    /** @param list<string> $expectedSlugs */
    #[Test]
    #[DataProvider('provideAScenarioCarriesTheRulesetsExactSlugsCases')]
    public function aScenarioCarriesTheRulesetsExactSlugs(int $playerCount, ?Region $region, array $expectedSlugs): void
    {
        $scenario = $this->registry->find($playerCount, $region);

        $this->assertInstanceOf(Scenario::class, $scenario);
        $this->assertSame($expectedSlugs, $scenario->empires);
    }

    /** @return iterable<string, array{int, ?Region, list<string>}> */
    public static function provideAScenarioCarriesTheRulesetsExactSlugsCases(): iterable
    {
        yield 'three players in the west' => [3, Region::West, ['hatti', 'hellas', 'minoa']];

        yield 'nine players in the east' => [9, Region::East, ['babylon', 'dravidia', 'indus', 'kushan', 'maurya', 'nubia', 'parthia', 'persia', 'saba']];
    }

    #[Test]
    #[DataProvider('provideACombinedScenarioSeatsEveryPlayerFromBothBoxesCases')]
    public function aCombinedScenarioSeatsEveryPlayerFromBothBoxes(int $playerCount): void
    {
        $scenario = $this->registry->find($playerCount, null);

        $this->assertInstanceOf(Scenario::class, $scenario);
        $this->assertCount($playerCount, $scenario->empires);
        $this->assertSame([Region::West, Region::East], $scenario->blocks);
        $this->assertNotInstanceOf(\App\State\Region::class, $scenario->soleBlock());
    }

    public static function provideACombinedScenarioSeatsEveryPlayerFromBothBoxesCases(): iterable
    {
        yield 'ten players' => [10];

        yield 'twelve players' => [12];

        yield 'eighteen players' => [18];
    }

    #[Test]
    public function aScenarioBelowTenPlayersIsPlayedOnASingleBox(): void
    {
        $scenario = $this->registry->find(6, Region::East);

        $this->assertInstanceOf(Scenario::class, $scenario);
        $this->assertSame([Region::East], $scenario->blocks);
        $this->assertSame(Region::East, $scenario->soleBlock());
    }

    #[Test]
    #[DataProvider('provideAPairingTheRulesetIgnoresIsNoScenarioAtAllCases')]
    public function aPairingTheRulesetIgnoresIsNoScenarioAtAll(int $playerCount, ?Region $region): void
    {
        $this->assertNotInstanceOf(\App\Rules\Ruleset\Scenario::class, $this->registry->find($playerCount, $region));
    }

    /** @return iterable<string, array{int, ?Region}> */
    public static function provideAPairingTheRulesetIgnoresIsNoScenarioAtAllCases(): iterable
    {
        yield 'player count above every scenario' => [19, null];
        yield 'a single box above nine players, where both are required' => [12, Region::East];
        yield 'both boxes below ten players, where only one is played' => [6, null];
        yield 'both boxes at a count whose only row is its starting credits' => [3, null];
        yield 'a player count and a box that pair to nothing' => [2, Region::West];
    }

    /** @param list<?Region> $expectedSoleBlocks */
    #[Test]
    #[DataProvider('provideAPlayerCountOffersEveryScenarioItCanBePlayedAsCases')]
    public function aPlayerCountOffersEveryScenarioItCanBePlayedAs(int $playerCount, array $expectedSoleBlocks): void
    {
        $blocks = array_map(
            static fn (Scenario $scenario): ?Region => $scenario->soleBlock(),
            $this->registry->forPlayerCount($playerCount),
        );

        $this->assertSame($expectedSoleBlocks, $blocks);
    }

    /** @return iterable<string, array{int, list<?Region>}> */
    public static function provideAPlayerCountOffersEveryScenarioItCanBePlayedAsCases(): iterable
    {
        yield 'a count split by box offers both, west first' => [9, [Region::West, Region::East]];

        yield 'a combined count offers one scenario, on no single box' => [10, [null]];

        yield 'a count the ruleset ignores offers nothing' => [19, []];
    }

    #[Test]
    public function playerCountsContainsAllScenarioCounts(): void
    {
        $counts = $this->registry->playerCounts();

        $this->assertSame(range(3, 18), $counts);
    }

    /** @param array<string, int> $expectedCredits */
    #[Test]
    #[DataProvider('provideAScenarioCarriesItsStartingCreditsCases')]
    public function aScenarioCarriesItsStartingCredits(int $playerCount, ?Region $region, array $expectedCredits): void
    {
        $scenario = $this->registry->find($playerCount, $region);

        $this->assertInstanceOf(Scenario::class, $scenario);
        $this->assertSame($expectedCredits, $scenario->startingCredits);
    }

    /** @return iterable<string, array{int, ?Region, array<string, int>}> */
    public static function provideAScenarioCarriesItsStartingCreditsCases(): iterable
    {
        yield 'three players get ten per category' => [3, Region::West, ['art' => 10, 'civic' => 10, 'craft' => 10, 'religion' => 10, 'science' => 10]];

        yield 'four players get five per category' => [4, Region::East, ['art' => 5, 'civic' => 5, 'craft' => 5, 'religion' => 5, 'science' => 5]];

        yield 'a scenario with no credits row grants none' => [9, Region::West, []];
    }
}
