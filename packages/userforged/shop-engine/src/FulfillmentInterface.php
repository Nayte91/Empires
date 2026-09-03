<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Symfony\Component\Uid\Uuid;

/**
 * What "delivering" means (port): granting a license, adding a game advance
 * to a player, decrementing stock and printing a label, or nothing. Called
 * on confirmation; must be reversible (revoke) to support cancellation of
 * confirmed orders. The library never mutates the host's buyer itself — it
 * only knows the buyer's id.
 */
interface FulfillmentInterface
{
    /**
     * @param list<string> $productKeys
     * @param int          $window      the sales window the products were bought in. A
     *                                  confirmation may come late; a host that dates its
     *                                  deliveries must date the purchase, not the confirmation.
     */
    public function grant(Uuid $buyerId, array $productKeys, int $window): void;

    /** @param list<string> $productKeys */
    public function revoke(Uuid $buyerId, array $productKeys): void;
}
