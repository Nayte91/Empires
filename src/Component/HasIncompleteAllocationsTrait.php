<?php

declare(strict_types=1);

namespace App\Component;

use App\Game\AdvanceCatalog;
use App\Shop\Cart;

trait HasIncompleteAllocationsTrait
{
    /** Check if any option-promoted line has an unspent balance. */
    protected function isCartHasIncompleteAllocations(Cart $cart, AdvanceCatalog $advanceCatalog): bool
    {
        foreach ($cart->items as $item) {
            $target = $advanceCatalog->getAdvanceByName($item->key)?->promotion?->option?->budget;

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
