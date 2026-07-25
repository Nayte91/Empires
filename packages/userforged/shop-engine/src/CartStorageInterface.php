<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

/**
 * Port for cart persistence. The library never assumes carts live in a
 * session, a database row, or anywhere in particular — that choice belongs
 * to the host application's adapter.
 *
 * `$key` is an opaque storage key, not a buyer identifier: a single buyer
 * may hold several carts under distinct keys (a point-of-sale operator, for
 * instance, keeps one cart per buyer they are serving). Implementations
 * must not assume any relationship between the key and a buyer identity.
 */
interface CartStorageInterface
{
    public function load(string $key): Cart;

    public function save(string $key, Cart $cart): void;

    public function clear(string $key): void;
}
