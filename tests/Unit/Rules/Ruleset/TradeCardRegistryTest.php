<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Ruleset;

use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\Ruleset\TradeCard;
use App\Rules\Ruleset\TradeCardRegistry;
use App\Rules\Ruleset\TradeCardStack;
use App\Rules\Ruleset\TradeCardTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The printed tables mark a card that is not in a configuration with a dot, and a card you may draw
 * none of would be a nought. This class exists to hold those two apart: everything it asserts is a
 * rule of the game or a property of the schema, never a digit copied off a page. A wrong digit is a
 * ten-second fix; a schema that cannot say "absent" without saying "zero" is inherited by every
 * reader of the file for good.
 */
final class TradeCardRegistryTest extends TestCase
{
    private TradeCardRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TradeCardRegistry(\dirname(__DIR__, 4).'/config/game/trade_cards.yaml');
    }

    /**
     * The load-bearing assertion of the whole feature. Ochre is a West card and Flax its East twin;
     * at 12-14 the pool splits per block, so each is in one column and out of the other. assertNull
     * fails on 0, so any reader that ever collapses absence into a quantity dies here.
     */
    #[Test]
    public function aCardOutOfOneBlockCarriesNullInThatColumnAndNeverZero(): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor(12, null), 1);

        $ochre = $this->cardNamed($stack, 'Ochre');
        $flax = $this->cardNamed($stack, 'Flax');

        $this->assertNull($ochre->quantities[1]);
        $this->assertNull($flax->quantities[0]);
        $this->assertNotNull($ochre->quantities[0]);
        $this->assertNotNull($flax->quantities[1]);
    }

    /**
     * The net under the assertion above: zero is not a legitimate value anywhere in this schema, so
     * its appearance in any cell of any configuration is proof that absence has been collapsed.
     */
    #[Test]
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function noColumnOfAnyConfigurationEverHoldsAQuantityOfZero(int $playerCount, ?string $region): void
    {
        $quantities = $this->everyQuantityIn($this->registry->distributionFor($playerCount, $region));

        $this->assertNotEmpty($quantities);
        $this->assertNotContains(0, $quantities);
    }

    /**
     * The two ways the schema says "not in this pool" are not interchangeable. With a single column
     * a card that is out has no row at all; with two, it keeps its row and reads null in the block
     * it left. Neither may drift into the other, or a player reads a stack that does not exist.
     */
    #[Test]
    public function absenceIsAMissingRowInASingleColumnTableAndANullCellInATwoColumnOne(): void
    {
        $singleColumn = $this->registry->distributionFor(9, 'west');
        $twoColumns = $this->registry->distributionFor(12, null);

        $this->assertNotContains(null, $this->everyQuantityIn($singleColumn));
        $this->assertContains(null, $this->everyQuantityIn($twoColumns));
    }

    /** A card out of every column of the resolved configuration is not a row of nulls, it is no row. */
    #[Test]
    public function aCardOutOfEveryResolvedColumnIsDroppedFromItsStackAltogether(): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor(12, null), 1);

        $this->assertNotContains('Bone', $this->cardNames($stack));
        $this->assertContains('Bone', $this->cardNames($this->stackNumbered($this->registry->distributionFor(15, null), 1)));
    }

    /** Water is in no stack and has no quantity anywhere; the tables print a stack 0 the game does not deal. */
    #[Test]
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function everyConfigurationDealsNineNumberedStacksAndNeverWater(int $playerCount, ?string $region): void
    {
        $table = $this->registry->distributionFor($playerCount, $region);

        $this->assertSame(range(1, 9), array_map(static fn (TradeCardStack $stack): int => $stack->number, $table->stacks));
        $this->assertNotContains('Water', array_merge(...array_map($this->cardNames(...), $table->stacks)));
    }

    /**
     * The regularity the rulebooks never state outright, and the reason a card's game has to be a
     * fact the file carries rather than something derived by subtracting one table from the other.
     */
    #[Test]
    #[DataProvider('provideEveryStackCases')]
    public function everyStackHoldsTwoWestOnlyTwoEastOnlyAndOneSharedCommodity(int $stackNumber): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor(15, null), $stackNumber);

        $byGame = array_count_values(array_map(static fn (TradeCard $card): string => $card->game, $this->cardsOfType($stack, 'commodity')));
        ksort($byGame);

        $this->assertSame(['east' => 2, 'shared' => 1, 'west' => 2], $byGame);
    }

    /**
     * The in-out-in rhythm that breaks naive schemas: the shared commodity is dealt below twelve,
     * leaves play entirely when the pool splits per block, then comes back halved into both blocks.
     */
    #[Test]
    #[DataProvider('provideEveryStackCases')]
    public function theSharedCommodityOfAStackLeavesPlayAtTwelveToFourteenAndReturnsToBothBlocksAtFifteenToEighteen(int $stackNumber): void
    {
        $atNine = $this->cardsOfType($this->stackNumbered($this->registry->distributionFor(9, 'west'), $stackNumber), 'commodity', 'shared');
        $atTwelve = $this->cardsOfType($this->stackNumbered($this->registry->distributionFor(12, null), $stackNumber), 'commodity', 'shared');
        $atFifteen = $this->cardsOfType($this->stackNumbered($this->registry->distributionFor(15, null), $stackNumber), 'commodity', 'shared');

        $this->assertCount(1, $atNine);
        $this->assertSame([], $atTwelve);
        $this->assertCount(1, $atFifteen);
        $this->assertNotContains(null, $atFifteen[0]->quantities);
    }

    /** Minor calamities are not used below nine players, and follow the shared commodity out of the split pool. */
    #[Test]
    #[DataProvider('provideEveryStackThatHoldsCalamitiesCases')]
    public function theMinorCalamityOfAStackFollowsTheSameInOutInRhythmAsTheSharedCommodity(int $stackNumber): void
    {
        $present = static fn (array $cards): bool => 1 === \count($cards);

        $this->assertFalse($present($this->minorCalamitiesOf(3, 'west', $stackNumber)));
        $this->assertTrue($present($this->minorCalamitiesOf(9, 'west', $stackNumber)));
        $this->assertTrue($present($this->minorCalamitiesOf(10, null, $stackNumber)));
        $this->assertFalse($present($this->minorCalamitiesOf(12, null, $stackNumber)));
        $this->assertTrue($present($this->minorCalamitiesOf(15, null, $stackNumber)));
    }

    #[Test]
    #[DataProvider('provideEveryStackThatHoldsCalamitiesCases')]
    public function everyStackFromTwoToNineHoldsExactlyOneMinorAndTwoMajorCalamities(int $stackNumber): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor(15, null), $stackNumber);

        $this->assertCount(1, $this->cardsOfType($stack, 'minor_calamity'));
        $this->assertCount(2, $this->cardsOfType($stack, 'major_calamity'));
    }

    /** The first stack is the one irregular stack: it is dealt in every configuration and never carries a calamity. */
    #[Test]
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function theFirstStackCarriesNoCalamityInAnyConfiguration(int $playerCount, ?string $region): void
    {
        $stack = $this->stackNumbered($this->registry->distributionFor($playerCount, $region), 1);

        $this->assertSame([], $this->cardsOfType($stack, 'minor_calamity'));
        $this->assertSame([], $this->cardsOfType($stack, 'major_calamity'));
    }

    /** A major calamity is a single card wherever it is dealt — no configuration ever doubles one. */
    #[Test]
    #[DataProvider('provideEveryDefinedDistributionCases')]
    public function aMajorCalamityIsASingleCardInEveryColumnThatDealsIt(int $playerCount, ?string $region): void
    {
        $table = $this->registry->distributionFor($playerCount, $region);

        $quantities = [];

        foreach ($table->stacks as $stack) {
            foreach ($this->cardsOfType($stack, 'major_calamity') as $card) {
                $quantities = array_merge($quantities, $card->quantities);
            }
        }

        $this->assertNotEmpty($quantities);
        $this->assertSame([1], array_values(array_unique($quantities)));
    }

    /**
     * The two rulebooks print the shared cards twice, and that redundancy is the only cross-check the
     * transcription has: a slip in either table shows up here as a disagreement rather than as a
     * quietly wrong stack.
     */
    #[Test]
    #[DataProvider('provideTheEastAndWestTablesAgreeOnEveryCardTheyShareCases')]
    public function theEastAndWestTablesAgreeOnEveryCardTheyShare(int $playerCount): void
    {
        $east = $this->sharedCardsOf($this->registry->distributionFor($playerCount, 'east'));
        $west = $this->sharedCardsOf($this->registry->distributionFor($playerCount, 'west'));

        $this->assertNotEmpty($east);
        $this->assertEquals($east, $west);
    }

    /** @return iterable<string, array{int}> */
    public static function provideTheEastAndWestTablesAgreeOnEveryCardTheyShareCases(): iterable
    {
        yield 'below nine players, where only the major calamities are shared' => [3];

        yield 'at nine players, where the shared commodities and minor calamities join them' => [9];
    }

    /** The brackets are the ones scenarios.yaml already draws; the labels name the column a player reads. */
    #[Test]
    #[DataProvider('provideTheBracketsResolveToTheColumnsOfTheScenarioCases')]
    public function theBracketsResolveToTheColumnsOfTheScenario(int $playerCount, ?string $region, array $expectedColumns): void
    {
        $this->assertSame($expectedColumns, $this->registry->distributionFor($playerCount, $region)->columns);
    }

    /**
     * @return iterable<string, array{int, null|string, list<string>}>
     */
    public static function provideTheBracketsResolveToTheColumnsOfTheScenarioCases(): iterable
    {
        yield 'the smallest east game' => [3, 'east', ['3-8 players']];

        yield 'the last count before the nine-player column' => [8, 'west', ['3-8 players']];

        yield 'nine players read a column of their own' => [9, 'east', ['9 players']];

        yield 'ten players merge into one region-less pool' => [10, null, ['10-11 players']];

        yield 'eleven players read that same pool' => [11, null, ['10-11 players']];

        yield 'twelve players split the pool per block' => [12, null, ['West block', 'East block']];

        yield 'fourteen players still split it' => [14, null, ['West block', 'East block']];

        yield 'fifteen players split it under the same two headings' => [15, null, ['West block', 'East block']];

        yield 'eighteen players close the largest bracket' => [18, null, ['West block', 'East block']];
    }

    /**
     * The bracket a player reads is not named by its heading. Both split brackets say "West block"
     * and "East block", and a consumer that keyed off the label alone would serve one for the other.
     */
    #[Test]
    public function theTwoSplitBracketsShareTheirHeadingsAndNotTheirData(): void
    {
        $twelveToFourteen = $this->registry->distributionFor(12, null);
        $fifteenToEighteen = $this->registry->distributionFor(15, null);

        $this->assertSame($twelveToFourteen->columns, $fifteenToEighteen->columns);
        $this->assertNotEquals($twelveToFourteen->stacks, $fifteenToEighteen->stacks);
    }

    /**
     * Below ten players a game is East or West and never both, so a region-less one names no
     * configuration at all. An empty table is the answer; a table of empty stacks would read as a bug.
     */
    #[Test]
    public function aGameBelowTenPlayersWithNoRegionResolvesToACompletelyEmptyTable(): void
    {
        $table = $this->registry->distributionFor(3, null);

        $this->assertSame([], $table->columns);
        $this->assertSame([], $table->stacks);
    }

    /**
     * The binding, asserted from the other side: every configuration scenarios.yaml defines is dealt
     * a full nine stacks here. It is what stops the distribution growing a taxonomy of its own —
     * add a scenario bracket without a distribution and this goes red the same day.
     */
    #[Test]
    public function everyConfigurationTheScenariosDefineIsDealtAFullNineStacks(): void
    {
        $scenarios = new ScenarioRegistry(\dirname(__DIR__, 4).'/config/game/scenarios.yaml');

        foreach ($scenarios->playerCounts() as $playerCount) {
            foreach ($scenarios->regionsFor($playerCount) ?: [null] as $region) {
                $table = $this->registry->distributionFor($playerCount, $region);

                $this->assertNotEmpty($table->columns);
                $this->assertCount(9, $table->stacks);
            }
        }
    }

    /**
     * The registry raises at load on a bracket the cards name but the file never declares. This test
     * adds the direction it does not check: a declared bracket no card ever uses is a dead
     * declaration, and the two sets have to stay the same one.
     */
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

    /** @return iterable<string, array{int, null|string}> */
    public static function provideEveryDefinedDistributionCases(): iterable
    {
        yield 'the east game below nine players' => [3, 'east'];

        yield 'the east game at nine players' => [9, 'east'];

        yield 'the west game below nine players' => [3, 'west'];

        yield 'the west game at nine players' => [9, 'west'];

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

    /** @return list<TradeCard> */
    private function minorCalamitiesOf(int $playerCount, ?string $region, int $stackNumber): array
    {
        return $this->cardsOfType($this->stackNumbered($this->registry->distributionFor($playerCount, $region), $stackNumber), 'minor_calamity');
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
