<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

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
    // REFACTOR-WHEN: a non-Presentation consumer needs to address a cart — move CartKey down to Rules/Shop/.

    public static function shop(Player $player): string
    {
        return $player->id->toRfc4122();
    }

    public static function pos(Player $player): string
    {
        return 'pos.'.$player->id->toRfc4122();
    }
}
