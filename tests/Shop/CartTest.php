<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Cart;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    #[Test]
    public function addAppendsKey(): void
    {
        $cart = new Cart();

        $cart->add('pottery');

        self::assertSame(['pottery'], $cart->keys());
    }

    #[Test]
    public function addDeduplicatesExistingKey(): void
    {
        $cart = new Cart();

        $cart->add('pottery');
        $cart->add('pottery');

        self::assertSame(['pottery'], $cart->keys());
    }

    #[Test]
    public function removeReindexesItems(): void
    {
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');
        $cart->add('democracy');

        $cart->remove('agriculture');

        self::assertSame([0 => 'pottery', 1 => 'democracy'], $cart->keys());
    }

    #[Test]
    public function removeUnknownKeyIsNoOp(): void
    {
        $cart = new Cart();
        $cart->add('pottery');

        $cart->remove('democracy');

        self::assertSame(['pottery'], $cart->keys());
    }

    #[Test]
    public function clearEmptiesItems(): void
    {
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');

        $cart->clear();

        self::assertSame([], $cart->keys());
    }

    #[Test]
    public function hasReflectsPresence(): void
    {
        $cart = new Cart();
        $cart->add('pottery');

        self::assertTrue($cart->has('pottery'));
        self::assertFalse($cart->has('agriculture'));
    }

    #[Test]
    public function isEmptyReflectsState(): void
    {
        $cart = new Cart();

        self::assertTrue($cart->isEmpty());

        $cart->add('pottery');

        self::assertFalse($cart->isEmpty());
    }

    #[Test]
    public function withGiftReplacesTheIntentForTheGivenKey(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');

        $cart->withGift('anatomy', 'astronavigation');

        self::assertSame('astronavigation', $cart->items[0]->gift);
    }

    #[Test]
    public function withGiftNullClearsAPreviouslyChosenGift(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->withGift('anatomy', null);

        self::assertNull($cart->items[0]->gift);
    }

    #[Test]
    public function withGiftOnAnUnknownKeyIsNoOp(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');

        $cart->withGift('democracy', 'astronavigation');

        self::assertNull($cart->items[0]->gift);
    }

    #[Test]
    public function withGiftAppendsTheTargetsOwnIntentIfMissing(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');

        $cart->withGift('anatomy', 'astronavigation');

        self::assertSame(['anatomy', 'astronavigation'], $cart->keys());
    }

    #[Test]
    public function withGiftDoesNotDuplicateTheTargetsIntentIfAlreadyPresent(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->add('astronavigation');

        $cart->withGift('anatomy', 'astronavigation');

        self::assertSame(['anatomy', 'astronavigation'], $cart->keys());
    }

    #[Test]
    public function withGiftRemovesTheStaleTargetsIntentWhenTheGiftChanges(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->withGift('anatomy', 'coinage');

        self::assertSame(['anatomy', 'coinage'], $cart->keys());
    }

    #[Test]
    public function withGiftRemovesTheStaleTargetsIntentWhenRevoked(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->withGift('anatomy', null);

        self::assertSame(['anatomy'], $cart->keys());
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

        self::assertSame(['anatomy', 'philosophy', 'astronavigation', 'coinage'], $cart->keys());
    }

    #[Test]
    public function removingTheSourceCascadesToRemoveTheGiftTargetsIntent(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->remove('anatomy');

        self::assertSame([], $cart->keys());
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

        self::assertSame(['philosophy', 'astronavigation'], $cart->keys());
    }

    #[Test]
    public function removingTheGiftTargetClearsTheSourcesGiftPointer(): void
    {
        $cart = new Cart();
        $cart->add('anatomy');
        $cart->withGift('anatomy', 'astronavigation');

        $cart->remove('astronavigation');

        self::assertSame(['anatomy'], $cart->keys());
        self::assertNull($cart->items[0]->gift);
    }

    #[Test]
    public function withAllocationAddsToTheGivenCategory(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('monument', 'craft', 5);

        self::assertSame(['craft' => 5], $cart->items[0]->allocation);
    }

    #[Test]
    public function withAllocationAccumulatesAcrossCalls(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('monument', 'craft', 5);
        $cart->withAllocation('monument', 'craft', 5);
        $cart->withAllocation('monument', 'science', 10);

        self::assertSame(['craft' => 10, 'science' => 10], $cart->items[0]->allocation);
    }

    #[Test]
    public function withAllocationClampsAtZero(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('monument', 'craft', 5);
        $cart->withAllocation('monument', 'craft', -20);

        self::assertSame(['craft' => 0], $cart->items[0]->allocation);
    }

    #[Test]
    public function withAllocationOnAnUnknownKeyIsNoOp(): void
    {
        $cart = new Cart();
        $cart->add('monument');

        $cart->withAllocation('democracy', 'craft', 5);

        self::assertSame([], $cart->items[0]->allocation);
    }
}
