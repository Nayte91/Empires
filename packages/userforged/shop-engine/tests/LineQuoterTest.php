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
use Userforged\ShopEngine\Service\LineQuoter;
use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakeFacetProvider;
use Userforged\ShopEngine\Tests\Support\FakePriceResolver;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use Userforged\ShopEngine\Tests\Support\FakeProductProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineQuoterTest extends TestCase
{
    private const array FACETS = ['craft', 'science'];

    /**
     * The engine's contract, not a pricing rule: a gift line's stamped
     * AppliedPromotion->amount is whatever PriceResolverInterface resolved
     * the gifted product to, for this buyer — never a value the engine
     * derives itself. Any pricing rule (credits, loyalty tiers, flat rates)
     * plugs in behind the same seam and this must still hold.
     */
    #[Test]
    public function quoteStampsAGiftedLinesAppliedPromotionAmountWithWhateverThePriceResolverReturned(): void
    {
        $anatomy = new FakeProduct(key: 'anatomy', cost: 270, facets: ['science'], promotion: new ProductPromotion(gift: ['science' => 100]));
        $astronavigation = new FakeProduct(key: 'astronavigation', cost: 80, facets: ['science']);

        $lineQuoter = $this->lineQuoterFor([$anatomy, $astronavigation], ['astronavigation' => 42]);

        $lines = $lineQuoter->quote([new LineIntent('anatomy', gift: 'astronavigation')], new FakeBuyer());

        $this->assertCount(2, $lines);
        $giftLine = $lines[1];
        $this->assertSame('astronavigation', $giftLine->key);
        $this->assertSame(42, $giftLine->promotion->amount);
    }

    /**
     * quotePreview() exists so a buyer halfway through spending an elective
     * budget never meets an exception page: the promotion is simply left
     * unapplied for that render pass. The guarantee only means something
     * next to the test below — quote(), which submit and checkout call, must
     * still refuse the very same input.
     */
    #[Test]
    public function quotePreviewFallsBackToUnpromotedPricingWhenTheAllocationIsIncomplete(): void
    {
        $lineQuoter = $this->lineQuoterFor([$this->electiveProduct()]);

        $lines = $lineQuoter->quotePreview([new LineIntent('monument')], new FakeBuyer());

        $this->assertEquals([new OrderLine('monument', 180)], $lines);
    }

    #[Test]
    public function quoteRefusesTheIncompleteAllocationThatQuotePreviewTolerates(): void
    {
        $lineQuoter = $this->lineQuoterFor([$this->electiveProduct()]);

        $this->expectException(PromotionException::class);

        $lineQuoter->quote([new LineIntent('monument')], new FakeBuyer());
    }

    #[Test]
    public function quotePreviewAppliesThePromotionOnceTheAllocationIsComplete(): void
    {
        $lineQuoter = $this->lineQuoterFor([$this->electiveProduct()]);

        $lines = $lineQuoter->quotePreview([new LineIntent('monument', allocation: ['craft' => 10, 'science' => 10])], new FakeBuyer());

        $this->assertInstanceOf(AppliedPromotion::class, $lines[0]->promotion);
        $this->assertSame(PromotionType::Option, $lines[0]->promotion->type);
        $this->assertSame(['craft' => 10, 'science' => 10], $lines[0]->promotion->allocation);
    }

    /**
     * A passthrough, but a published one: LineQuoter exposes it precisely so a
     * caller reconstructing intents from persisted lines does not have to take
     * a PromotionEngine dependency of its own.
     */
    #[Test]
    public function intentsFromLinesReconstructsTheIntentsBehindPersistedLines(): void
    {
        $lineQuoter = $this->lineQuoterFor([]);

        $intents = $lineQuoter->intentsFromLines([
            new OrderLine('anatomy', 270),
            new OrderLine('astronavigation', 0, new AppliedPromotion(PromotionType::Gift, 'anatomy', 80)),
        ]);

        $this->assertSame('anatomy', $intents[0]->key);
        $this->assertSame('astronavigation', $intents[0]->gift);
    }

    private function electiveProduct(): FakeProduct
    {
        return new FakeProduct(
            key: 'monument',
            cost: 180,
            promotion: new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5)),
        );
    }

    /**
     * @param list<FakeProduct>  $products
     * @param array<string, int> $prices
     */
    private function lineQuoterFor(array $products, array $prices = []): LineQuoter
    {
        return new LineQuoter(
            new FakeProductProvider($products),
            new PriceCalculator(new FakePriceResolver($prices)),
            new PromotionEngine(),
            new FakeFacetProvider(self::FACETS),
        );
    }
}
