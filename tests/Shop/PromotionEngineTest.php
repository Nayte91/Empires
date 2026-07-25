<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Dto\LineIntent;
use App\Shop\Dto\OrderLine;
use App\Shop\Exception\PromotionException;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\ElectiveBenefit;
use App\Shop\Promotion\ProductPromotion;
use App\Shop\Promotion\PromotionEngine;
use App\Shop\Promotion\PromotionType;
use App\Tests\Shop\Support\FakeProduct;
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

        self::assertSame(220, $result[0]->netCost);
        self::assertNotInstanceOf(AppliedPromotion::class, $result[0]->promotion);

        self::assertSame(160, $result[1]->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $result[1]->promotion);
        self::assertSame(PromotionType::Discount, $result[1]->promotion->type);
        self::assertSame('library', $result[1]->promotion->source);
        self::assertSame(40, $result[1]->promotion->amount);

        self::assertSame(60, $result[2]->netCost);
        self::assertNotInstanceOf(AppliedPromotion::class, $result[2]->promotion);
    }

    #[Test]
    public function libraryAloneInTheOrderIsANoOp(): void
    {
        $lines = [new OrderLine('library', 220)];
        $inOrder = [$this->makeAdvance('library', new ProductPromotion(discount: ['any' => 40]))];

        $result = new PromotionEngine()->apply($lines, $inOrder);

        self::assertSame(220, $result[0]->netCost);
        self::assertNotInstanceOf(AppliedPromotion::class, $result[0]->promotion);
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

        self::assertSame(0, $result[1]->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $result[1]->promotion);
        self::assertSame(30, $result[1]->promotion->amount);
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

        self::assertSame(160, $result[1]->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $result[1]->promotion);

        self::assertSame(200, $result[2]->netCost);
        self::assertNotInstanceOf(AppliedPromotion::class, $result[2]->promotion);
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

        self::assertSame(50, $result[0]->netCost);
        self::assertSame(45, $result[1]->netCost);

        self::assertSame(130, $result[2]->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $result[2]->promotion);
        self::assertSame('source_b', $result[2]->promotion->source);
        self::assertSame(30, $result[2]->promotion->amount);
    }

    #[Test]
    public function giftCandidatesOnlyMatchAdvancesInAGiftedFacet(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $scienceAdvance = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $artAdvance = $this->makeAdvance('sculpture', cost: 50, facets: ['art']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], [], [$scienceAdvance, $artAdvance]);

        self::assertSame(['astronavigation'], $candidates);
    }

    #[Test]
    public function giftCandidatesExcludeAdvancesAtOrAboveTheThreshold(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $cheap = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $atThreshold = $this->makeAdvance('coinage', cost: 100, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], [], [$cheap, $atThreshold]);

        self::assertSame(['astronavigation'], $candidates);
    }

    #[Test]
    public function giftCandidatesExcludeAlreadyOwnedAdvances(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $owned = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $free = $this->makeAdvance('empiricism', cost: 60, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, ['astronavigation'], [], [$owned, $free]);

        self::assertSame(['empiricism'], $candidates);
    }

    #[Test]
    public function giftCandidatesExcludeAdvancesAlreadyInTheOrder(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $inOrder = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $free = $this->makeAdvance('empiricism', cost: 60, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], ['astronavigation'], [$inOrder, $free]);

        self::assertSame(['empiricism'], $candidates);
    }

    #[Test]
    public function giftCandidatesReturnsEveryEligibleAdvance(): void
    {
        $granting = $this->makeAdvance('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
        $first = $this->makeAdvance('astronavigation', cost: 80, facets: ['science']);
        $second = $this->makeAdvance('empiricism', cost: 60, facets: ['science']);

        $candidates = new PromotionEngine()->giftCandidates($granting, [], [], [$first, $second]);

        self::assertSame(['astronavigation', 'empiricism'], $candidates);
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

        self::assertCount(2, $result);
        $giftLine = $result[1];
        self::assertSame('astronavigation', $giftLine->key);
        self::assertSame(0, $giftLine->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $giftLine->promotion);
        self::assertSame(PromotionType::Gift, $giftLine->promotion->type);
        self::assertSame('anatomy', $giftLine->promotion->source);
        self::assertSame(80, $giftLine->promotion->amount);
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

        self::assertSame(180, $result[0]->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $result[0]->promotion);
        self::assertSame(PromotionType::Option, $result[0]->promotion->type);
        self::assertSame('monument', $result[0]->promotion->source);
        self::assertSame(['craft' => 10, 'science' => 10], $result[0]->promotion->allocation);
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

        self::assertCount(1, $intents);
        self::assertSame('monument', $intents[0]->key);
        self::assertSame(['craft' => 10, 'science' => 10], $intents[0]->allocation);
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

        self::assertCount(3, $intents);
        self::assertSame('anatomy', $intents[0]->key);
        self::assertSame('astronavigation', $intents[0]->gift);
        self::assertSame('pottery', $intents[1]->key);
        self::assertNull($intents[1]->gift);
        self::assertSame('astronavigation', $intents[2]->key);
        self::assertNull($intents[2]->gift);
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

        self::assertCount(2, $result);
        $giftLine = $result[1];
        self::assertSame('written_record', $giftLine->key);
        self::assertSame(0, $giftLine->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $giftLine->promotion);
        self::assertSame(PromotionType::Gift, $giftLine->promotion->type, 'the Gift stamp must survive the Option merge');
        self::assertSame('anatomy', $giftLine->promotion->source);
        self::assertSame(60, $giftLine->promotion->amount);
        self::assertSame(['civic' => 5, 'science' => 5], $giftLine->promotion->allocation);

        $roundTrippedIntents = new PromotionEngine()->intentsFromLines($result);

        self::assertCount(2, $roundTrippedIntents);
        self::assertSame('anatomy', $roundTrippedIntents[0]->key);
        self::assertSame('written_record', $roundTrippedIntents[0]->gift);
        self::assertSame('written_record', $roundTrippedIntents[1]->key);
        self::assertNull($roundTrippedIntents[1]->gift);
        self::assertSame(['civic' => 5, 'science' => 5], $roundTrippedIntents[1]->allocation);
    }

    /** @param list<string> $facets */
    private function makeAdvance(string $key, ?ProductPromotion $promotion = null, int $cost = 0, array $facets = []): FakeProduct
    {
        return new FakeProduct(key: $key, cost: $cost, facets: $facets, promotion: $promotion);
    }
}
