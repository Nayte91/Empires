<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Shop\BuyerInterface;
use Symfony\Component\Uid\Uuid;

/** The Game→Shop seam for BuyerInterface: a Player's shop-relevant facts, snapshotted at ShopConnector::buyerFor() time. */
final readonly class PlayerBuyer implements BuyerInterface
{
    /**
     * @param list<string>       $ownedKeys
     * @param array<string, int> $electiveCredits
     */
    public function __construct(
        public Uuid $id,
        public array $ownedKeys,
        public array $electiveCredits,
    ) {}
}
