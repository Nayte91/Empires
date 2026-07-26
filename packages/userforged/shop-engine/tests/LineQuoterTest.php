<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Promotion\ProductPromotion;
use Userforged\ShopEngine\Promotion\PromotionEngine;
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

        $productProvider = new FakeProductProvider([$anatomy, $astronavigation]);
        $buyer = new FakeBuyer();

        $lineQuoter = new LineQuoter(
            $productProvider,
            new PriceCalculator(new FakePriceResolver(['astronavigation' => 42])),
            new PromotionEngine(),
            new FakeFacetProvider(),
        );

        $lines = $lineQuoter->quote([new LineIntent('anatomy', gift: 'astronavigation')], $buyer);

        $this->assertCount(2, $lines);
        $giftLine = $lines[1];
        $this->assertSame('astronavigation', $giftLine->key);
        $this->assertSame(42, $giftLine->promotion->amount);
    }
}
