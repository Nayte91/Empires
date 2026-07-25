<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Category;
use App\Repository\OrderRepository;
use App\Shop\BuyerInterface;
use App\Shop\FacetProviderInterface;
use App\Shop\OrderStatus;
use App\Shop\Promotion\OptionCredits;

/**
 * The Game→Shop seam: the shop's opaque ordering window is, in Empires, the
 * game turn. This class is the only place where that translation is allowed —
 * src/Shop/ never learns what a window stands for.
 */
final readonly class ShopConnector implements FacetProviderInterface
{
    public function __construct(private OrderRepository $orderRepository) {}

    public function currentWindow(GameSession $game): int
    {
        return $game->currentTurn;
    }

    /**
     * The Game→Shop seam for the generic "facet" concept: the shop's Option
     * promotion splits a budget across opaque "facets" — in Empires, those
     * are the advance categories. src/Shop/ never learns what a facet stands
     * for.
     *
     * @return list<string>
     */
    public function facets(): array
    {
        return array_map(static fn (Category $category): string => $category->value, Category::cases());
    }

    /**
     * The Game→Shop seam for BuyerInterface: fetches the player's validated
     * orders, aggregates their Option allocations, and snapshots the result
     * into a PlayerBuyer.
     *
     * A Pending order's own lines are excluded here — not in OptionCredits —
     * which is the load-bearing half of the no-self-crediting invariant: a
     * buyer must be built (or rebuilt) at the exact call site of a quote, and
     * never reused across a validate/erase/disown mutation, or it goes stale
     * and self-credits. Never cache this by player id across such a boundary.
     */
    public function buyerFor(Player $player): BuyerInterface
    {
        $confirmedLines = [];

        foreach ($this->orderRepository->findByPlayer($player) as $order) {
            if (OrderStatus::Validated !== $order->status) {
                continue;
            }

            foreach ($order->lines() as $line) {
                $confirmedLines[] = $line;
            }
        }

        return new PlayerBuyer(
            id: $player->id,
            ownedKeys: $player->advances,
            electiveCredits: OptionCredits::aggregate($confirmedLines),
        );
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
