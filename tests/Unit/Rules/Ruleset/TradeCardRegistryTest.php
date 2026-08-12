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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TradeCardRegistryTest extends TestCase
{
    private TradeCardRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TradeCardRegistry(\dirname(__DIR__, 4).'/config/game/trade_cards.yaml');
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
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function noColumnOfAnyConfigurationEverHoldsAQuantityOfZero(int $playerCount, ?Region $region): void
    {
        $quantities = $this->everyQuantityIn($this->registry->distributionFor($this->scenario($playerCount, $region)));

        $this->assertNotEmpty($quantities);
        $this->assertNotContains(0, $quantities);
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
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function everyConfigurationDealsNineNumberedStacksAndNeverWater(int $playerCount, ?Region $region): void
    {
        $table = $this->registry->distributionFor($this->scenario($playerCount, $region));

        $this->assertSame(range(1, 9), array_map(static fn (TradeCardStack $stack): int => $stack->number, $table->stacks));
        $this->assertNotContains('Water', array_merge(...array_map($this->cardNames(...), $table->stacks)));
    }

    #[Test]
    #[DataProvider('provideEveryStackCases')]
    public function everyStackHoldsTwoWestOnlyTwoEastOnlyAndOneSharedCommodity(int $stackNumber): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor($this->scenario(15, null)), $stackNumber);

        $byGame = array_count_values(array_map(static fn (TradeCard $card): string => $card->game, $this->cardsOfType($stack, 'commodity')));
        ksort($byGame);

        $this->assertSame(['east' => 2, 'shared' => 1, 'west' => 2], $byGame);
    }

    #[Test]
    #[DataProvider('provideEveryStackCases')]
    public function theSharedCommodityOfAStackLeavesPlayAtTwelveToFourteenAndReturnsToBothBlocksAtFifteenToEighteen(int $stackNumber): void
    {
        $atNine = $this->cardsOfType($this->stackNumbered($this->registry->distributionFor($this->scenario(9, Region::West)), $stackNumber), 'commodity', 'shared');
        $atTwelve = $this->cardsOfType($this->stackNumbered($this->registry->distributionFor($this->scenario(12, null)), $stackNumber), 'commodity', 'shared');
        $atFifteen = $this->cardsOfType($this->stackNumbered($this->registry->distributionFor($this->scenario(15, null)), $stackNumber), 'commodity', 'shared');

        $this->assertCount(1, $atNine);
        $this->assertSame([], $atTwelve);
        $this->assertCount(1, $atFifteen);
        $this->assertNotContains(null, $atFifteen[0]->quantities);
    }

    #[Test]
    #[DataProvider('provideEveryStackThatHoldsCalamitiesCases')]
    public function theMinorCalamityOfAStackFollowsTheSameInOutInRhythmAsTheSharedCommodity(int $stackNumber): void
    {
        $present = static fn (array $cards): bool => 1 === \count($cards);

        $this->assertFalse($present($this->minorCalamitiesOf(3, Region::West, $stackNumber)));
        $this->assertTrue($present($this->minorCalamitiesOf(9, Region::West, $stackNumber)));
        $this->assertTrue($present($this->minorCalamitiesOf(10, null, $stackNumber)));
        $this->assertFalse($present($this->minorCalamitiesOf(12, null, $stackNumber)));
        $this->assertTrue($present($this->minorCalamitiesOf(15, null, $stackNumber)));
    }

    #[Test]
    #[DataProvider('provideEveryStackThatHoldsCalamitiesCases')]
    public function everyStackFromTwoToNineHoldsExactlyOneMinorAndTwoMajorCalamities(int $stackNumber): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor($this->scenario(15, null)), $stackNumber);

        $this->assertCount(1, $this->cardsOfType($stack, 'minor_calamity'));
        $this->assertCount(2, $this->cardsOfType($stack, 'major_calamity'));
    }

    #[Test]
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function theFirstStackCarriesNoCalamityInAnyConfiguration(int $playerCount, ?Region $region): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor($this->scenario($playerCount, $region)), 1);

        $this->assertSame([], $this->cardsOfType($stack, 'minor_calamity'));
        $this->assertSame([], $this->cardsOfType($stack, 'major_calamity'));
    }

    #[Test]
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function aMajorCalamityIsASingleCardInEveryColumnThatDealsIt(int $playerCount, ?Region $region): void
    {
        $table = $this->registry->distributionFor($this->scenario($playerCount, $region));

        $quantities = [];

        foreach ($table->stacks as $stack) {
            foreach ($this->cardsOfType($stack, 'major_calamity') as $card) {
                $quantities = array_merge($quantities, $card->quantities);
            }
        }

        $this->assertNotEmpty($quantities);
        $this->assertSame([1], array_values(array_unique($quantities)));
    }

    #[Test]
    #[DataProvider('provideTheEastAndWestTablesAgreeOnEveryCardTheyShareCases')]
    public function theEastAndWestTablesAgreeOnEveryCardTheyShare(int $playerCount): void
    {
        $east = $this->sharedCardsOf($this->registry->distributionFor($this->scenario($playerCount, Region::East)));
        $west = $this->sharedCardsOf($this->registry->distributionFor($this->scenario($playerCount, Region::West)));

        $this->assertNotEmpty($east);
        $this->assertEquals($east, $west);
    }

    /** @return iterable<string, array{int}> */
    public static function provideTheEastAndWestTablesAgreeOnEveryCardTheyShareCases(): iterable
    {
        yield 'below nine players, where only the major calamities are shared' => [3];

        yield 'at nine players, where the shared commodities and minor calamities join them' => [9];
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

    #[Test]
    public function everyConfigurationTheScenariosDefineIsDealtAFullNineStacks(): void
    {
        $scenarios = $this->scenarioRegistry();

        foreach ($scenarios->playerCounts() as $playerCount) {
            foreach ($scenarios->forPlayerCount($playerCount) as $scenario) {
                $table = $this->registry->distributionFor($scenario);

                $this->assertNotEmpty($table->columns);
                $this->assertCount(9, $table->stacks);
            }
        }
    }

    #[Test]
    public function everyBracketTheCardsNameIsDeclaredAndEveryDeclaredBracketIsUsed(): void
    {
        $tradeCards = Yaml::parseFile(\dirname(__DIR__, 4).'/config/game/trade_cards.yaml')['trade_cards'];

        $used = [];

        foreach ($tradeCards['stacks'] as $stack) {
            foreach ($stack['cards'] as $card) {
                $used = [...$used, ...array_keys($card['quantities'])];
            }
        }

        $declared = $tradeCards['brackets'];
        sort($declared);
        $used = array_unique($used);
        sort($used);

        $this->assertSame($declared, $used);
    }

    /** @return iterable<string, array{int, null|Region}> */
    public static function provideEveryDefinedDistributionCases(): iterable
    {
        yield 'the east game below nine players' => [3, Region::East];
        yield 'the east game at nine players' => [9, Region::East];
        yield 'the west game below nine players' => [3, Region::West];
        yield 'the west game at nine players' => [9, Region::West];
        yield 'the combined game at ten to eleven' => [10, null];
        yield 'the combined game at twelve to fourteen' => [12, null];
        yield 'the combined game at fifteen to eighteen' => [15, null];
    }

    /** @return iterable<string, array{int}> */
    public static function provideEveryStackCases(): iterable
    {
        foreach (range(1, 9) as $stackNumber) {
            yield "stack {$stackNumber}" => [$stackNumber];
        }
    }

    /** @return iterable<string, array{int}> */
    public static function provideEveryStackThatHoldsCalamitiesCases(): iterable
    {
        foreach (range(2, 9) as $stackNumber) {
            yield "stack {$stackNumber}" => [$stackNumber];
        }
    }

    private function scenario(int $playerCount, ?Region $region): ?Scenario
    {
        return $this->scenarioRegistry()->find($playerCount, $region);
    }

    private function scenarioRegistry(): ScenarioRegistry
    {
        return new ScenarioRegistry(\dirname(__DIR__, 4).'/config/game/scenarios.yaml');
    }

    /** @return list<TradeCard> */
    private function minorCalamitiesOf(int $playerCount, ?Region $region, int $stackNumber): array
    {
        return $this->cardsOfType($this->stackNumbered($this->registry->distributionFor($this->scenario($playerCount, $region)), $stackNumber), 'minor_calamity');
    }

    /** @return list<TradeCard> */
    private function sharedCardsOf(TradeCardTable $table): array
    {
        $shared = [];

        foreach ($table->stacks as $stack) {
            $shared = array_merge($shared, array_values(array_filter($stack->cards, static fn (TradeCard $card): bool => 'shared' === $card->game)));
        }

        return $shared;
    }

    /** @return list<TradeCard> */
    private function cardsOfType(TradeCardStack $stack, string $type, ?string $game = null): array
    {
        return array_values(array_filter(
            $stack->cards,
            static fn (TradeCard $card): bool => $card->type === $type && (null === $game || $card->game === $game),
        ));
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
