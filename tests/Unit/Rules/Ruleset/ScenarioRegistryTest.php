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
}
