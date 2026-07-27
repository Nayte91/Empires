<?php

declare(strict_types=1);

namespace App\Rules\Shop;

use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\BuyerInterface;

/** The Game→Shop seam for BuyerInterface: a Player's shop-relevant facts, snapshotted at ShopConnector::buyerFor() time. */
final readonly class PlayerBuyer implements BuyerInterface
{
    /**
     * @param list<string>      $ownedKeys
     * @param list<Entitlement> $entitlements
     */
    public function __construct(
        public Uuid $id,
        public array $ownedKeys,
        public array $entitlements,
    ) {}
}
