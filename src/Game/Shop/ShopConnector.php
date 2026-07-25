<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Category;
use App\Repository\OrderRepository;
use App\Shop\OrderStatus;

/**
 * The Game→Shop seam: the shop's opaque ordering window is, in Empires, the
 * game turn. This class is the only place where that translation is allowed —
 * src/Shop/ never learns what a window stands for.
 */
final readonly class ShopConnector
{
    public function __construct(private OrderRepository $orderRepository) {}

    public function currentWindow(GameSession $game): int
    {
        return $game->currentTurn;
    }

    /**
     * The Game→Shop seam for the generic "bucket" concept: the shop's Option
     * promotion splits a budget across opaque "buckets" — in Empires, those
     * are the advance categories. src/Shop/ never learns what a bucket stands
     * for.
     *
     * @return list<string>
     */
    public function buckets(): array
    {
        return array_map(static fn (Category $category): string => $category->value, Category::cases());
    }

    /** @return list<int> */
    public function windowsToErase(Player $player, int $turn): array
    {
        $order = $this->orderRepository->findOneByPlayerAndWindow($player, $turn);

        if (!$order instanceof Order) {
            return [];
        }

        if (OrderStatus::Validated !== $order->status) {
            return [$turn];
        }

        return array_map(
            static fn (Order $o): int => $o->turn,
            $this->orderRepository->findByPlayerFromTurn($player, $turn),
        );
    }
}
