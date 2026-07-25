<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Entity\Player;
use App\Repository\PlayerRepository;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\FulfillmentInterface;

/**
 * The Game→Shop seam for FulfillmentInterface: in Empires, "delivering" an
 * order means adding the purchased slugs to the player's owned advances.
 * Resolves the buyer id to a Player via PlayerRepository — the library
 * never touches App\Entity\Player itself.
 */
final readonly class AdvanceFulfillment implements FulfillmentInterface
{
    public function __construct(private PlayerRepository $playerRepository) {}

    public function grant(Uuid $buyerId, array $productKeys): void
    {
        $this->resolve($buyerId)->ownAdvances($productKeys);
    }

    public function revoke(Uuid $buyerId, array $productKeys): void
    {
        $this->resolve($buyerId)->disownAdvances($productKeys);
    }

    private function resolve(Uuid $buyerId): Player
    {
        return $this->playerRepository->find($buyerId) ?? throw new \RuntimeException('Player not found.');
    }
}
