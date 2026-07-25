<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Repository\PlayerRepository;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\BuyerProviderInterface;

/**
 * The Game→Shop seam for BuyerProviderInterface: resolves a buyer id to a
 * Player via PlayerRepository, then delegates to ShopConnector::buyerFor()
 * for the actual snapshot. The library never touches App\Entity\Player
 * itself. Mirrors AdvanceFulfillment's find() ?? throw.
 */
final readonly class PlayerBuyerProvider implements BuyerProviderInterface
{
    public function __construct(
        private PlayerRepository $players,
        private ShopConnector $connector,
    ) {}

    public function buyerFor(Uuid $buyerId): BuyerInterface
    {
        return $this->connector->buyerFor($this->players->find($buyerId) ?? throw new \RuntimeException('Player not found.'));
    }
}
