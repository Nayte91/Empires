<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;
use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Service\LineQuoter;

/** @phpstan-type OrderCard array{player: Player, seat: int, turn: int, status: string, slugs: list<string>, total: int, vp: int, alsoErases: list<int>} */
final readonly class OrderCardProvider
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private AdvanceRegistry $advanceRegistry,
        private LineQuoter $lineQuoter,
        private ShopConnector $shopConnector,
    ) {}

    /** @return list<OrderCard> */
    public function cardsFor(Game $game, OrderCardSort $sort): array
    {
        /** @var array<string, array<int, Order>> $ordersByPlayer */
        $ordersByPlayer = [];

        foreach ($this->orderRepository->findByGame($game) as $order) {
            $ordersByPlayer[$order->player->id->toRfc4122()][$order->turn] = $order;
        }

        $cards = [];

        foreach (array_values($game->players->toArray()) as $seat => $player) {
            $byTurn = $ordersByPlayer[$player->id->toRfc4122()] ?? [];
            $turns = array_unique(array_merge(range($game->currentTurn, 1), array_keys($byTurn)));
            $buyer = $this->buyerForQuoting($player, $byTurn);

            foreach ($turns as $turn) {
                $cards[] = $this->cardFor($player, $seat, $turn, $byTurn, $buyer);
            }
        }

        usort($cards, $sort->compare(...));

        return $cards;
    }

    /** @param array<int, Order> $byTurn */
    private function buyerForQuoting(Player $player, array $byTurn): ?BuyerInterface
    {
        if (array_any($byTurn, fn($order) => OrderStatus::Pending === $order->status)) {
            return $this->shopConnector->buyerFor($player);
        }

        return null;
    }

    /**
     * @param array<int, Order> $byTurn
     *
     * @return OrderCard
     */
    private function cardFor(Player $player, int $seat, int $turn, array $byTurn, ?BuyerInterface $buyer): array
    {
        $order = $byTurn[$turn] ?? null;
        $slugs = $order?->keys() ?? [];

        /** @var list<Advance> $advances */
        $advances = $this->advanceRegistry->getAdvancesByNames($slugs);

        return [
            'player' => $player,
            'seat' => $seat,
            'turn' => $turn,
            'status' => match (true) {
                $order instanceof Order => $order->status->value,
                $turn === $player->game->currentTurn => 'missing',
                default => 'empty',
            },
            'slugs' => $slugs,
            'total' => $this->totalOf($order, $buyer),
            'vp' => array_sum(array_map(static fn (Advance $advance): int => $advance->points, $advances)),
            'alsoErases' => $byTurn
                    |> array_keys(...)
                    |> (fn($x) => array_filter($x, static fn(int $t): bool => $t > $turn))
                    |> array_values(...),
        ];
    }

    private function totalOf(?Order $order, ?BuyerInterface $buyer): int
    {
        if (!$order instanceof Order) {
            return 0;
        }

        if (OrderStatus::Validated === $order->status) {
            return $order->total ?? 0;
        }

        if (!$buyer instanceof BuyerInterface) {
            return 0;
        }

        return array_sum(array_map(
            static fn (OrderLine $line): int => $line->netCost,
            $this->lineQuoter->quote($this->lineQuoter->intentsFromLines($order->lines()), $buyer),
        ));
    }
}
