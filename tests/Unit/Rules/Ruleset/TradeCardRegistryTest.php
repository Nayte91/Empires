<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Ruleset;

use App\Rules\Ruleset\Scenario;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\Ruleset\TradeCard;
use App\Rules\Ruleset\TradeCardRegistry;
use App\Rules\Ruleset\TradeCardStack;
use App\Rules\Ruleset\TradeCardTable;
use App\State\Region;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TradeCardRegistryTest extends TestCase
{
    private TradeCardRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = GameConfig::tradeCardRegistry();
    }

    #[Test]
    public function aCardOutOfOneBlockCarriesNullInThatColumnAndNeverZero(): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor($this->scenario(12, null)), 1);

        $ochre = $this->cardNamed($stack, 'Ochre');
        $flax = $this->cardNamed($stack, 'Flax');

        $this->assertNull($ochre->quantities[1]);
        $this->assertNull($flax->quantities[0]);
        $this->assertNotNull($ochre->quantities[0]);
        $this->assertNotNull($flax->quantities[1]);
    }

    #[Test]
    public function absenceIsAMissingRowInASingleColumnTableAndANullCellInATwoColumnOne(): void
    {
        $singleColumn = $this->registry->distributionFor($this->scenario(9, Region::West));
        $twoColumns = $this->registry->distributionFor($this->scenario(12, null));

        $this->assertNotContains(null, $this->everyQuantityIn($singleColumn));
        $this->assertContains(null, $this->everyQuantityIn($twoColumns));
    }

    #[Test]
    public function aCardOutOfEveryResolvedColumnIsDroppedFromItsStackAltogether(): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor($this->scenario(12, null)), 1);

        $this->assertNotContains('Bone', $this->cardNames($stack));
        $this->assertContains('Bone', $this->cardNames($this->stackNumbered($this->registry->distributionFor($this->scenario(15, null)), 1)));
    }

    #[Test]
    #[DataProvider('provideTheBracketsResolveToTheColumnsOfTheScenarioCases')]
    public function theBracketsResolveToTheColumnsOfTheScenario(int $playerCount, ?Region $region, array $expectedColumns): void
    {
        $this->assertSame($expectedColumns, $this->registry->distributionFor($this->scenario($playerCount, $region))->columns);
    }

    /** @return iterable<string, array{int, null|Region, list<string>}> */
    public static function provideTheBracketsResolveToTheColumnsOfTheScenarioCases(): iterable
    {
        yield 'the smallest east game' => [3, Region::East, ['3-8 players']];
        yield 'the last count before the nine-player column' => [8, Region::West, ['3-8 players']];
        yield 'nine players read a column of their own' => [9, Region::East, ['9 players']];
        yield 'ten players merge into one region-less pool' => [10, null, ['10-11 players']];
        yield 'eleven players read that same pool' => [11, null, ['10-11 players']];
        yield 'twelve players split the pool per block' => [12, null, ['West block', 'East block']];
        yield 'fourteen players still split it' => [14, null, ['West block', 'East block']];
        yield 'fifteen players split it under the same two headings' => [15, null, ['West block', 'East block']];
        yield 'eighteen players close the largest bracket' => [18, null, ['West block', 'East block']];
    }

    #[Test]
    public function theTwoSplitBracketsShareTheirHeadingsAndNotTheirData(): void
    {
        $twelveToFourteen = $this->registry->distributionFor($this->scenario(12, null));
        $fifteenToEighteen = $this->registry->distributionFor($this->scenario(15, null));

        $this->assertSame($twelveToFourteen->columns, $fifteenToEighteen->columns);
        $this->assertNotEquals($twelveToFourteen->stacks, $fifteenToEighteen->stacks);
    }

    #[Test]
    public function aGameThatMatchesNoScenarioResolvesToACompletelyEmptyTable(): void
    {
        $this->assertNotInstanceOf(Scenario::class, $this->scenario(3, null));

        $table = $this->registry->distributionFor(null);

        $this->assertSame([], $table->columns);
        $this->assertSame([], $table->stacks);
    }

    private function scenario(int $playerCount, ?Region $region): ?Scenario
    {
        return $this->scenarioRegistry()->find($playerCount, $region);
    }

    private function scenarioRegistry(): ScenarioRegistry
    {
        return GameConfig::scenarioRegistry();
    }

    /** @return list<string> */
    private function cardNames(TradeCardStack $stack): array
    {
        return array_map(static fn (TradeCard $card): string => $card->name, $stack->cards);
    }

    /** @return list<null|int> */
    private function everyQuantityIn(TradeCardTable $table): array
    {
        $quantities = [];

        foreach ($table->stacks as $stack) {
            foreach ($stack->cards as $card) {
                $quantities = array_merge($quantities, $card->quantities);
            }
        }

        return $quantities;
    }

    private function cardNamed(TradeCardStack $stack, string $name): TradeCard
    {
        $matches = array_values(array_filter($stack->cards, static fn (TradeCard $card): bool => $card->name === $name));

        $this->assertCount(1, $matches);

        return $matches[0];
    }

    private function stackNumbered(TradeCardTable $table, int $number): TradeCardStack
    {
        $matches = array_values(array_filter($table->stacks, static fn (TradeCardStack $stack): bool => $stack->number === $number));

        $this->assertCount(1, $matches);

        return $matches[0];
    }
}
