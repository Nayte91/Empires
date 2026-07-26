<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Symfony\Component\Uid\Uuid;

/**
 * Resolves a buyer id into a BuyerInterface (port). The library never owns
 * users — the host maps its own identity/repository onto this interface.
 */
interface BuyerProviderInterface
{
    public function buyerFor(Uuid $buyerId): BuyerInterface;
}
