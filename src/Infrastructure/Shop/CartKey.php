<?php

declare(strict_types=1);

namespace App\Infrastructure\Shop;

use App\State\Player;

/**
 * The one place the two cart storage keys are composed. A player holds two
 * carts at once — the one they fill in their own shop, and the one an
 * operator fills for them at the POS — and Userforged\ShopEngine\CartStorageInterface
 * only ever sees the resulting opaque string, never a player.
 *
 * Both spellings are on-the-wire: SessionCartStorage prefixes them and stores
 * the cart under that name, so changing either one empties every cart a live
 * session is currently holding.
 */
final class CartKey
{
    public static function shop(Player $player): string
    {
        return $player->id->toRfc4122();
    }

    public static function pos(Player $player): string
    {
        return 'pos.'.$player->id->toRfc4122();
    }
}
