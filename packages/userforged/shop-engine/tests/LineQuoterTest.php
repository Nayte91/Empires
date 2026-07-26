<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Promotion\ProductPromotion;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Service\CreditPriceResolver;
use Userforged\ShopEngine\Service\LineQuoter;
use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakeFacetProvider;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use Userforged\ShopEngine\Tests\Support\FakeProductProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineQuoterTest extends TestCase
{
    /**
     * Characterizes the one deliberate behaviour change of this phase: the
     * gift line's stamped AppliedPromotion->amount now comes from the same
     * resolver as every other price in the quote, so a buyer's elective
     * credits reduce it exactly as they would a direct purchase — where it
     * previously only reflected owned-product credits.
     */
    #[Test]
    public function quoteStampsAGiftedLinesAppliedPromotionAmountWithTheElectiveCreditResolvedPrice(): void
    {
        $anatomy = new FakeProduct(key: 'anatomy', cost: 270, facets: ['science'], promotion: new ProductPromotion(gift: ['science' => 100]));
        $astronavigation = new FakeProduct(key: 'astronavigation', cost: 80, facets: ['science']);

        $productProvider = new FakeProductProvider([$anatomy, $astronavigation]);
        $buyer = new FakeBuyer(electiveCredits: ['science' => 20]);

        $lineQuoter = new LineQuoter(
            $productProvider,
            new PriceCalculator(new CreditPriceResolver($productProvider)),
            new PromotionEngine(),
            new FakeFacetProvider(),
        );

        $lines = $lineQuoter->quote([new LineIntent('anatomy', gift: 'astronavigation')], $buyer);

        $this->assertCount(2, $lines);
        $giftLine = $lines[1];
        $this->assertSame('astronavigation', $giftLine->key);
        $this->assertSame(60, $giftLine->promotion->amount);
    }
}
