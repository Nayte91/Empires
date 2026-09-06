<?php

declare(strict_types=1);

namespace App\Rules\Shop;

use App\Rules\Ruleset\Category;
use App\State\CreditEntry;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\FacetProviderInterface;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\OptionCredits;

final readonly class ShopConnector implements FacetProviderInterface
{
    public const int OPENING_TURN = 6;

    public function __construct(private OrderRepositoryInterface $orderRepository) {}

    public function currentWindow(Game $game): int
    {
        return $game->currentTurn;
    }

    /** @return list<string> */
    public function facets(): array
    {
        return array_map(static fn (Category $category): string => $category->value, Category::cases());
    }

    public function buyerFor(Player $player): PlayerBuyer
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
            entitlements: [
                ...$this->ledgerEntitlements($player->creditLedger),
                ...$this->electiveEntitlements($confirmedLines),
            ],
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

    /**
     * @param list<CreditEntry> $creditLedger
     *
     * @return list<Entitlement>
     */
    private function ledgerEntitlements(array $creditLedger): array
    {
        $balances = [];

        foreach ($creditLedger as $entry) {
            if ($entry->value >= 0) {
                $balances[$entry->scope] = ($balances[$entry->scope] ?? 0) + $entry->value;

                continue;
            }

            $withdrawn = min(-$entry->value, $balances[$entry->scope] ?? 0);
            $balances[$entry->scope] = ($balances[$entry->scope] ?? 0) - $withdrawn;
        }

        $entitlements = [];

        foreach ($balances as $scope => $balance) {
            $entitlements[] = new Entitlement($scope, $balance);
        }

        return $entitlements;
    }

    /**
     * @param list<OrderLine> $confirmedLines
     *
     * @return list<Entitlement>
     */
    private function electiveEntitlements(array $confirmedLines): array
    {
        $entitlements = [];

        foreach (OptionCredits::aggregate($confirmedLines) as $scope => $value) {
            $entitlements[] = new Entitlement($scope, $value);
        }

        return $entitlements;
    }
}
