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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PromotionEngineTest extends TestCase
{
    private const array FACETS = ['art', 'civic', 'craft', 'religion', 'science'];

    #[Test]
    public function aDiscountAppliesToTheMostExpensiveOtherLine(): void
    {
        $lines = [
            new OrderLine('library', 220),
            new OrderLine('democracy', 200),
            new OrderLine('pottery', 60),
        ];
        $inOrder = [
            self::makeProduct('library', new ProductPromotion(discount: ['any' => 40])),
            self::makeProduct('democracy'),
            self::makeProduct('pottery'),
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
    public function aDiscountSourceAloneInTheOrderIsANoOp(): void
    {
        $lines = [new OrderLine('library', 220)];
        $inOrder = [self::makeProduct('library', new ProductPromotion(discount: ['any' => 40]))];

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
            self::makeProduct('library', new ProductPromotion(discount: ['any' => 40])),
            self::makeProduct('pottery'),
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
            self::makeProduct('library', new ProductPromotion(discount: ['any' => 40])),
            self::makeProduct('democracy'),
            self::makeProduct('mathematics'),
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
            self::makeProduct('source_a', new ProductPromotion(discount: ['any' => 40])),
            self::makeProduct('source_b', new ProductPromotion(discount: ['any' => 30])),
            self::makeProduct('target'),
        ];

        $result = new PromotionEngine()->apply($lines, $inOrder);

        $this->assertSame(50, $result[0]->netCost);
        $this->assertSame(45, $result[1]->netCost);

        $this->assertSame(130, $result[2]->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $result[2]->promotion);
        $this->assertSame('source_b', $result[2]->promotion->source);
        $this->assertSame(30, $result[2]->promotion->amount);
    }

    /**
     * @param list<string>      $ownedKeys
     * @param list<string>      $inOrderKeys
     * @param list<FakeProduct> $catalog
     * @param list<string>      $expected
     */
    #[Test]
    #[DataProvider('provideGiftCandidatesOffersOnlyWhatTheGiftActuallyGrantsCases')]
    public function giftCandidatesOffersOnlyWhatTheGiftActuallyGrants(array $ownedKeys, array $inOrderKeys, array $catalog, array $expected): void
    {
        $granting = $this->giftGrantingProduct();

        $candidates = new PromotionEngine()->giftCandidates($granting, $ownedKeys, $inOrderKeys, $catalog);

        $this->assertSame($expected, $candidates);
    }

    public static function provideGiftCandidatesOffersOnlyWhatTheGiftActuallyGrantsCases(): iterable
    {
        yield 'a product outside every gifted facet is excluded' => [
            [],
            [],
            [self::makeProduct('astronavigation', cost: 80, facets: ['science']), self::makeProduct('sculpture', cost: 50, facets: ['art'])],
            ['astronavigation'],
        ];

        yield 'a product at or above the facet threshold is excluded' => [
            [],
            [],
            [self::makeProduct('astronavigation', cost: 80, facets: ['science']), self::makeProduct('coinage', cost: 100, facets: ['science'])],
            ['astronavigation'],
        ];

        yield 'a product the buyer already owns is excluded' => [
            ['astronavigation'],
            [],
            [self::makeProduct('astronavigation', cost: 80, facets: ['science']), self::makeProduct('empiricism', cost: 60, facets: ['science'])],
            ['empiricism'],
        ];

        yield 'a product already sitting in the order is excluded' => [
            [],
            ['astronavigation'],
            [self::makeProduct('astronavigation', cost: 80, facets: ['science']), self::makeProduct('empiricism', cost: 60, facets: ['science'])],
            ['empiricism'],
        ];

        yield 'every eligible product is offered, not merely the first' => [
            [],
            [],
            [self::makeProduct('astronavigation', cost: 80, facets: ['science']), self::makeProduct('empiricism', cost: 60, facets: ['science'])],
            ['astronavigation', 'empiricism'],
        ];
    }

    #[Test]
    public function applyWithAValidGiftIntentAppendsAZeroCostLineStampedWithWhatItWouldHaveCost(): void
    {
        $granting = $this->giftGrantingProduct();
        $astronavigation = self::makeProduct('astronavigation', cost: 80, facets: ['science']);

        $lines = [new OrderLine('anatomy', 270)];
        $intents = [new LineIntent('anatomy', gift: 'astronavigation')];
        $catalog = [$granting, $astronavigation];
        $catalogNetCosts = ['anatomy' => 270, 'astronavigation' => 80];

        $result = new PromotionEngine()->apply($lines, [$granting], $intents, $catalog, $catalogNetCosts, []);

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
        $granting = $this->giftGrantingProduct();
        $tooExpensive = self::makeProduct('coinage', cost: 200, facets: ['science']);

        $lines = [new OrderLine('anatomy', 270)];
        $intents = [new LineIntent('anatomy', gift: 'coinage')];

        $this->expectException(PromotionException::class);

        new PromotionEngine()->apply($lines, [$granting], $intents, [$granting, $tooExpensive], ['anatomy' => 270, 'coinage' => 200], []);
    }

    #[Test]
    public function applyWithAValidAllocationStampsTheSourceLineAndLeavesNetCostUnchanged(): void
    {
        $monument = $this->electiveProduct();

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument', allocation: ['craft' => 10, 'science' => 10])];

        $result = new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);

        $this->assertSame(180, $result[0]->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $result[0]->promotion);
        $this->assertSame(PromotionType::Option, $result[0]->promotion->type);
        $this->assertSame('monument', $result[0]->promotion->source);
        $this->assertSame(['craft' => 10, 'science' => 10], $result[0]->promotion->allocation);
    }

    /**
     * The two reasons are not interchangeable and the message is the only
     * thing telling them apart: an under-spent budget is a transient state
     * while the buyer is still choosing (allocationRequired), whereas a bad
     * facet, a wrong sum or an off-step amount can only reach the engine from
     * a client that bypassed the UI (invalidAllocation).
     *
     * @param array<string, int> $allocation
     */
    #[Test]
    #[DataProvider('provideApplyRejectsAnUnusableAllocationCases')]
    public function applyRejectsAnUnusableAllocation(array $allocation, string $expectedMessage): void
    {
        $monument = $this->electiveProduct();

        $lines = [new OrderLine('monument', 180)];
        $intents = [new LineIntent('monument', allocation: $allocation)];

        $this->expectException(PromotionException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        new PromotionEngine()->apply($lines, [$monument], $intents, facets: self::FACETS);
    }

    public static function provideApplyRejectsAnUnusableAllocationCases(): iterable
    {
        yield 'nothing allocated yet' => [[], '/Allocation required for promotion on "monument"/'];

        yield 'the budget only partially spent' => [['craft' => 10], '/Allocation required for promotion on "monument"/'];

        yield 'more than the budget spent' => [['craft' => 15, 'science' => 15], '/Invalid allocation for promotion on "monument"/'];

        yield 'a facet the host never declared' => [['nonsense' => 20], '/Invalid allocation for promotion on "monument"/'];

        yield 'an amount that is not a multiple of the step' => [['craft' => 12, 'science' => 8], '/Invalid allocation for promotion on "monument"/'];
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
        $granting = $this->giftGrantingProduct();
        $writtenRecord = self::makeProduct(
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
        $catalog = [$granting, $writtenRecord];
        $catalogNetCosts = ['anatomy' => 270, 'written_record' => 60];

        $result = new PromotionEngine()->apply($lines, [$granting], $intents, $catalog, $catalogNetCosts, [], self::FACETS);

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

    /** A product whose promotion gifts anything in the "science" facet costing under 100. */
    private function giftGrantingProduct(): FakeProduct
    {
        return self::makeProduct('anatomy', new ProductPromotion(gift: ['science' => 100]), cost: 270, facets: ['science']);
    }

    /** A product whose promotion hands the buyer a 20-point budget to split across facets, 5 at a time. */
    private function electiveProduct(): FakeProduct
    {
        return self::makeProduct('monument', new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)), cost: 180);
    }

    /** @param list<string> $facets */
    private static function makeProduct(string $key, ?ProductPromotion $promotion = null, int $cost = 0, array $facets = []): FakeProduct
    {
        return new FakeProduct(key: $key, cost: $cost, facets: $facets, promotion: $promotion);
    }
}
