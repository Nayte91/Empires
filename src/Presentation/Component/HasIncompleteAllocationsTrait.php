<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AdvanceRegistry;
use Userforged\ShopEngine\Cart;

trait HasIncompleteAllocationsTrait
{
    /** Check if any option-promoted line has an unspent balance. */
    protected function isCartHasIncompleteAllocations(Cart $cart, AdvanceRegistry $advanceRegistry): bool
    {
        foreach ($cart->items as $item) {
            $target = $advanceRegistry->getAdvanceByName($item->key)?->promotion?->option?->budget;

            if (null === $target) {
                continue;
            }

            if (array_sum($item->allocation) < $target) {
                return true;
            }
        }

        return false;
    }
}
