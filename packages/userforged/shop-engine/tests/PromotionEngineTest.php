<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Exception\PromotionException;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\ElectiveBenefit;
use Userforged\ShopEngine\Promotion\ProductPromotion;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Promotion\PromotionType;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PromotionEngineTest extends TestCase
{
    private const array FACETS = ['art', 'civic', 'craft', 'religion', 'science'];

    #[Test]
    public function libraryDiscountsTheMostExpensiveOtherLine(): void
    {
        $lines = [
            new OrderLine('library', 220),
            new OrderLine('democracy', 200),
            new OrderLine('pottery', 60),
        ];
        $inOrder = [
            $this->makeAdvance('library', new ProductPromotion(discount: ['any' => 40])),
            $this->makeAdvance('democracy'),
            $this->makeAdvance('pottery'),
        ];

        $result = new PromotionEngine()->apply($lines, $inOrder);

        $this->assertSame(220, $result[0]->netCost);
        $this->assertNotInstanceOf(AppliedPromotion::class, $result[0]->promotion);

        $this->assertSame(160, $result[1]->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $result[1]->promotion);
        $this->assertSame(PromotionType::Discount, $result[1]->promotion->type);
        $this->assertSame('library', $result[1]->promotion->source);
        $this->assertSame(40, $result[1]->promotion->amount);

        $this->assertSame(60, $result[2]->netCost);
        $this->assertNotInstanceOf(AppliedPromotion::class, $result[2]->promotion);
    }

    #[Test]
    public function libraryAloneInTheOrderIsANoOp(): void
    {
        $lines = [new OrderLine('library', 220)];
        $inOrder = [$this->makeAdvance('library', new ProductPromotion(discount: ['any' => 40]))];

        $result = new PromotionEngine()->apply($lines, $inOrder);

        $this->assertSame(220, $result[0]->netCost);
        $this->assertNotInstanceOf(AppliedPromotion::class, $result[0]->promotion);
    }

    #[Test]
    public function discountIsFlooredAtZeroAndStampsTheRealDeduction(): void
    {
        $lines = [
            new OrderLine('library', 220),
            new OrderLine('pottery', 30),
        ];
        $inOrder = [
            $this->makeAdvance('library', new ProductPromotion(discount: ['any' => 40])),
            $this->makeAdvance('pottery'),
        ];

        $result = new PromotionEngine()->apply($lines, $inOrder);

        $this->assertSame(0, $result[1]->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $result[1]->promotion);
        $this->assertSame(30, $result[1]->promotion->amount);
    }

    #[Test]
    public function tieBreaksOnTheFirstEncounteredLineAmongEquallyExpensiveOnes(): void
    {
        $lines = [
            new OrderLine('library', 220),
            new OrderLine('democracy', 200),
            new OrderLine('mathematics', 200),
        ];
        $inOrder = [
            $this->makeAdvance('library', new ProductPromotion(discount: ['any' => 40])),
            $this->makeAdvance('democracy'),
            $this->makeAdvance('mathematics'),
        ];

        $result = new PromotionEngine()->apply($lines, $inOrder);

        $this->assertSame(160, $result[1]->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $result[1]->promotion);

        $this->assertSame(200, $result[2]->netCost);
        $this->assertNotInstanceOf(AppliedPromotion::class, $result[2]->promotion);
    }

    #[Test]
    public function twoDiscountSourcesCumulateOnTheSameTargetLine(): void
    {
        $lines = [
            new OrderLine('source_a', 50),
            new OrderLine('source_b', 45),
            new OrderLine('target', 200),
        ];
        $inOrder = [
            $this->makeAdvance('source_a', new ProductPromotion(discount: ['any' => 40])),
            $this->makeAdvance('source_b', new ProductPromotion(discount: ['any' => 30])),
            $this->makeAdvance('target'),
        ];

        $result = new PromotionEngine()->apply($lines, $inOrder);

        $this->assertSame(50, $result[0]->netCost);
        $this->assertSame(45, $result[1]->netCost);

        $this->assertSame(130, $result[2]->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $result[2]->promotion);
        $this->assertSame('source_b', $result[2]->promotion->source);
        $this->assertSame(30, $result[2]->promotion->amount);
    }

    #[Test]
    public function giftCandidatesOnlyMatchAdvancesInAGiftedFacet(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $scienceAdvance = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $artAdvance = $this->makeAdvance('sculpture', cost: 50, facets: ['art']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], [], [$scienceAdvance, $artAdvance]);

        $this->assertSame(['astronavigation'], $candidates);
    }

    #[Test]
    public function giftCandidatesExcludeAdvancesAtOrAboveTheThreshold(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $cheap = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $atThreshold = $this->makeAdvance('coinage', cost: 100, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], [], [$cheap, $atThreshold]);

        $this->assertSame(['astronavigation'], $candidates);
    }

    #[Test]
    public function giftCandidatesExcludeAlreadyOwnedAdvances(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $owned = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $free = $this->makeAdvance('empiricism', cost: 60, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, ['astronavigation'], [], [$owned, $free]);

        $this->assertSame(['empiricism'], $candidates);
    }

    #[Test]
    public function giftCandidatesExcludeAdvancesAlreadyInTheOrder(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $inOrder = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $free = $this->makeAdvance('empiricism', cost: 60, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], ['astronavigation'], [$inOrder, $free]);

        $this->assertSame(['empiricism'], $candidates);
    }

    #[Test]
    public function giftCandidatesReturnsEveryEligibleAdvance(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $first = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $second = $this->makeAdvance('empiricism', cost: 60, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], [], [$first, $second]);

        $this->assertSame(['astronavigation', 'empiricism'], $candidates);
    }

    #[Test]
    public function applyWithAValidGiftIntentAppendsAZeroCostLineStampedWithWhatItWouldHaveCost(): void
    {
        $anatomy = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $astronavigation = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);

        $lines = [new OrderLine('anatomy', 270)];
        $intents = [new LineIntent('anatomy', gift: 'astronavigation')];
        $catalog = [$anatomy, $astronavigation];
        $catalogNetCosts = ['anatomy' => 270, 'astronavigation' => 80];

        $result = new PromotionEngine()->apply($lines, [$anatomy], $intents, $catalog, $catalogNetCosts, []);

        $this->assertCount(2, $result);
        $giftLine = $result[1];
        $this->assertSame('astronavigation', $giftLine->key);
        $this->assertSame(0, $giftLine->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $giftLine->promotion);
        $this->assertSame(PromotionType::Gift, $giftLine->promotion->type);
        $this->assertSame('anatomy', $giftLine->promotion->source);
        $this->assertSame(80, $giftLine->promotion->amount);
    }

    #[Test]
    public function applyWithAnInvalidGiftChoiceThrowsPromotionException(): void
    {
        $anatomy = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $tooExpensive = $this->makeAdvance('coinage', cost: 200, facets: ['science']);

        $lines = [new OrderLine('anatomy', 270)];
        $intents = [new LineIntent('anatomy', gift: 'coinage')];

        $this->expectException(PromotionException::class);

        new PromotionEngine()->apply($lines, [$anatomy], $intents, [$anatomy, $tooExpensive], ['anatomy' => 270, 'coinage' => 200], []);
    }

    #[Test]
    public function applyWithAValidAllocationStampsTheSourceLineAndLeavesNetCostUnchanged(): void
    {
        $monument = $this->makeAdvance('monument', new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)), cost: 180);

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument', allocation: ['craft' => 10, 'science' => 10])];

        $result = new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);

        $this->assertSame(180, $result[0]->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $result[0]->promotion);
        $this->assertSame(PromotionType::Option, $result[0]->promotion->type);
        $this->assertSame('monument', $result[0]->promotion->source);
        $this->assertSame(['craft' => 10, 'science' => 10], $result[0]->promotion->allocation);
    }

    #[Test]
    public function applyWithAnEmptyAllocationThrowsAllocationRequired(): void
    {
        $monument = $this->makeAdvance('monument', new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)), cost: 180);

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument')];

        $this->expectException(PromotionException::class);
        $this->expectExceptionMessageMatches('/Allocation required for promotion on "monument"/');

        new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);
    }

    #[Test]
    public function applyWithAPartialAllocationThrowsAllocationRequired(): void
    {
        $monument = $this->makeAdvance('monument', new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)), cost: 180);

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument', allocation: ['craft' => 10])];

        $this->expectException(PromotionException::class);
        $this->expectExceptionMessageMatches('/Allocation required for promotion on "monument"/');

        new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);
    }

    #[Test]
    public function applyWithAWrongSumThrowsInvalidAllocation(): void
    {
        $monument = $this->makeAdvance('monument', new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)), cost: 180);

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument', allocation: ['craft' => 15, 'science' => 15])];

        $this->expectException(PromotionException::class);
        $this->expectExceptionMessageMatches('/Invalid allocation for promotion on "monument"/');

        new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);
    }

    #[Test]
    public function applyWithAnUnknownFacetThrowsInvalidAllocation(): void
    {
        $monument = $this->makeAdvance('monument', new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)), cost: 180);

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument', allocation: ['nonsense' => 20])];

        $this->expectException(PromotionException::class);

        new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);
    }

    #[Test]
    public function applyWithANonMultipleOfFiveThrowsInvalidAllocation(): void
    {
        $monument = $this->makeAdvance('monument', new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)), cost: 180);

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument', allocation: ['craft' => 12, 'science' => 8])];

        $this->expectException(PromotionException::class);

        new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);
    }

    #[Test]
    public function intentsFromLinesRoundTripsAnOptionAllocation(): void
    {
        $lines = [
            new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])),
        ];

        $intents = new PromotionEngine()->intentsFromLines($lines);

        $this->assertCount(1, $intents);
        $this->assertSame('monument', $intents[0]->key);
        $this->assertSame(['craft' => 10, 'science' => 10], $intents[0]->allocation);
    }

    #[Test]
    public function intentsFromLinesFoldsAGiftLineIntoItsSourceIntentAndAlsoEmitsItsOwnIntent(): void
    {
        $lines = [
            new OrderLine('anatomy', 270),
            new OrderLine('pottery', 60),
            new OrderLine('astronavigation', 0, new AppliedPromotion(PromotionType::Gift, 'anatomy', 80)),
        ];

        $intents = new PromotionEngine()->intentsFromLines($lines);

        $this->assertCount(3, $intents);
        $this->assertSame('anatomy', $intents[0]->key);
        $this->assertSame('astronavigation', $intents[0]->gift);
        $this->assertSame('pottery', $intents[1]->key);
        $this->assertNull($intents[1]->gift);
        $this->assertSame('astronavigation', $intents[2]->key);
        $this->assertNull($intents[2]->gift);
    }

    #[Test]
    public function applyResolvesTheOptionPromotionOfAGiftAppendedLineAgainstTheCatalogAndPreservesItsGiftStamp(): void
    {
        $anatomy = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $writtenRecord = $this->makeAdvance(
            'written_record',
            new ProductPromotion(option: new ElectiveBenefit(budget: 10, step: 5)),
            cost: 60,
            facets: ['civic', 'science'],
        );

        $lines = [new OrderLine('anatomy', 270)];
        $intents = [
            new LineIntent('anatomy', gift: 'written_record'),
            new LineIntent('written_record', allocation: ['civic' => 5, 'science' => 5]),
        ];
        $catalog = [$anatomy, $writtenRecord];
        $catalogNetCosts = ['anatomy' => 270, 'written_record' => 60];

        $result = new PromotionEngine()->apply($lines, [$anatomy], $intents, $catalog, $catalogNetCosts, [], self::FACETS);

        $this->assertCount(2, $result);
        $giftLine = $result[1];
        $this->assertSame('written_record', $giftLine->key);
        $this->assertSame(0, $giftLine->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $giftLine->promotion);
        $this->assertSame(PromotionType::Gift, $giftLine->promotion->type, 'the Gift stamp must survive the Option merge');
        $this->assertSame('anatomy', $giftLine->promotion->source);
        $this->assertSame(60, $giftLine->promotion->amount);
        $this->assertSame(['civic' => 5, 'science' => 5], $giftLine->promotion->allocation);

        $roundTrippedIntents = new PromotionEngine()->intentsFromLines($result);

        $this->assertCount(2, $roundTrippedIntents);
        $this->assertSame('anatomy', $roundTrippedIntents[0]->key);
        $this->assertSame('written_record', $roundTrippedIntents[0]->gift);
        $this->assertSame('written_record', $roundTrippedIntents[1]->key);
        $this->assertNull($roundTrippedIntents[1]->gift);
        $this->assertSame(['civic' => 5, 'science' => 5], $roundTrippedIntents[1]->allocation);
    }

    /** @param list<string> $facets */
    private function makeAdvance(string $key, ?ProductPromotion $promotion = null, int $cost = 0, array $facets = []): FakeProduct
    {
        return new FakeProduct(key: $key, cost: $cost, facets: $facets, promotion: $promotion);
    }
}
