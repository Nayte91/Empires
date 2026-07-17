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

        self::assertSame(['pottery'], $cart->items);
    }

    #[Test]
    public function addDeduplicatesExistingKey(): void
    {
        $cart = new Cart();

        $cart->add('pottery');
        $cart->add('pottery');

        self::assertSame(['pottery'], $cart->items);
    }

    #[Test]
    public function removeReindexesItems(): void
    {
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');
        $cart->add('democracy');

        $cart->remove('agriculture');

        self::assertSame([0 => 'pottery', 1 => 'democracy'], $cart->items);
    }

    #[Test]
    public function removeUnknownKeyIsNoOp(): void
    {
        $cart = new Cart();
        $cart->add('pottery');

        $cart->remove('democracy');

        self::assertSame(['pottery'], $cart->items);
    }

    #[Test]
    public function clearEmptiesItems(): void
    {
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');

        $cart->clear();

        self::assertSame([], $cart->items);
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
}
