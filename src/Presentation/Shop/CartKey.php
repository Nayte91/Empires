<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

use App\State\Player;

final class CartKey
{
    // REFACTOR-WHEN: a non-Presentation consumer needs to address a cart — move CartKey down to Rules/Shop/.

    public static function shop(Player $player): string
    {
        return $player->id->toRfc4122();
    }

    public static function pos(Player $player, int $turn): string
    {
        return 'pos.'.$player->id->toRfc4122().'.'.$turn;
    }
}
