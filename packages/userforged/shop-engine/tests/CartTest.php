<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Cart;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    #[Test]
    public function addAppendsKey(): void
    {
        $cart = new Cart();

        $cart->add('pottery');

        $this->assertSame(['pottery'], $cart->keys());
    }

    #[Test]
    public function addDeduplicatesExistingKey(): void
    {
        $cart = new Cart();

        $cart->add('pottery');
        $cart->add('pottery');

        $this->assertSame(['pottery'], $cart->keys());
    }

    #[Test]
    public function removeReindexesItems(): void
    {
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');
        $cart->add('democracy');

        $cart->remove('agriculture');

        $this->assertSame([0 => 'pottery', 1 => 'democracy'], $cart->keys());
    }

    #[Test]
    public function removeUnknownKeyIsNoOp(): void
    {
        $cart = new Cart();
        $cart->add('pottery');

        $cart->remove('democracy');

        $this->assertSame(['pottery'], $cart->keys());
    }

    #[Test]
    public function clearEmptiesItems(): void
    {
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');

        $cart->clear();

        $this->assertSame([], $cart->keys());
    }

    #[Test]
    public function hasReflectsPresence(): void
    {
        $cart = new Cart();
        $cart->add('pottery');

        $this->assertTrue($cart->has('pottery'));
        $this->assertFalse($cart->has('agriculture'));
    }

    #[Test]
    public function isEmptyReflectsState(): void
    {
        $cart = new Cart();

        $this->assertTrue($cart->isEmpty());

        $cart->add('pottery');

        $this->assertFalse($cart->isEmpty());
    }

    #[Test]
    public function withGiftReplacesTheIntentForTheGivenKey(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');

        $cart->withGift('anatomy', 'astronavigation');

        $this->assertSame('astronavigation', $cart->items[0]->gift);
    }

    #[Test]
    public function withGiftNullClearsAPreviouslyChosenGift(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->withGift('anatomy', null);

        $this->assertNull($cart->items[0]->gift);
    }

    #[Test]
    public function withGiftOnAnUnknownKeyIsNoOp(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');

        $cart->withGift('democracy', 'astronavigation');

        $this->assertNull($cart->items[0]->gift);
    }

    #[Test]
    public function withGiftAppendsTheTargetsOwnIntentIfMissing(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');

        $cart->withGift('anatomy', 'astronavigation');

        $this->assertSame(['anatomy', 'astronavigation'], $cart->keys());
    }

    #[Test]
    public function withGiftDoesNotDuplicateTheTargetsIntentIfAlreadyPresent(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->add('astronavigation');

        $cart->withGift('anatomy', 'astronavigation');

        $this->assertSame(['anatomy', 'astronavigation'], $cart->keys());
    }

    #[Test]
    public function withGiftRemovesTheStaleTargetsIntentWhenTheGiftChanges(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->withGift('anatomy', 'coinage');

        $this->assertSame(['anatomy', 'coinage'], $cart->keys());
    }

    #[Test]
    public function withGiftRemovesTheStaleTargetsIntentWhenRevoked(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->withGift('anatomy', null);

        $this->assertSame(['anatomy'], $cart->keys());
    }

    #[Test]
    public function withGiftKeepsTheStaleTargetsIntentIfStillReferencedByAnotherSource(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->add('philosophy');
        $cart->withGift('anatomy', 'astronavigation');
        $cart->withGift('philosophy', 'astronavigation');

        $cart->withGift('anatomy', 'coinage');

        $this->assertSame(['anatomy', 'philosophy', 'astronavigation', 'coinage'], $cart->keys());
    }

    #[Test]
    public function removingTheSourceCascadesToRemoveTheGiftTargetsIntent(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->remove('anatomy');

        $this->assertSame([], $cart->keys());
    }

    #[Test]
    public function removingTheSourceKeepsTheTargetsIntentIfStillReferencedByAnotherSource(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->add('philosophy');
        $cart->withGift('anatomy', 'astronavigation');
        $cart->withGift('philosophy', 'astronavigation');

        $cart->remove('anatomy');

        $this->assertSame(['philosophy', 'astronavigation'], $cart->keys());
    }

    #[Test]
    public function removingTheGiftTargetClearsTheSourcesGiftPointer(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->remove('astronavigation');

        $this->assertSame(['anatomy'], $cart->keys());
        $this->assertNull($cart->items[0]->gift);
    }

    #[Test]
    public function withAllocationAddsToTheGivenFacet(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('monument', 'craft', 5);

        $this->assertSame(['craft' => 5], $cart->items[0]->allocation);
    }

    #[Test]
    public function withAllocationAccumulatesAcrossCalls(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('monument', 'craft', 5);
        $cart->withAllocation('monument', 'craft', 5);
        $cart->withAllocation('monument', 'science', 10);

        $this->assertSame(['craft' => 10, 'science' => 10], $cart->items[0]->allocation);
    }

    #[Test]
    public function withAllocationClampsAtZero(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('monument', 'craft', 5);
        $cart->withAllocation('monument', 'craft', -20);

        $this->assertSame(['craft' => 0], $cart->items[0]->allocation);
    }

    #[Test]
    public function withAllocationOnAnUnknownKeyIsNoOp(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('democracy', 'craft', 5);

        $this->assertSame([], $cart->items[0]->allocation);
    }
}
