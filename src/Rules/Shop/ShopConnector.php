<?php

declare(strict_types=1);

namespace App\Rules\Shop;

use App\Infrastructure\Repository\OrderRepository;
use App\Rules\Ruleset\Category;
use App\State\CreditEntry;
use App\State\GameSession;
use App\State\Order;
use App\State\Player;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\FacetProviderInterface;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\OptionCredits;

/**
 * The Game→Shop seam: the shop's opaque ordering window is, in Empires, the
 * game turn. This class is the only place where that translation is allowed —
 * packages/userforged/shop-engine/ never learns what a window stands for.
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
     * are the advance categories. packages/userforged/shop-engine/ never
     * learns what a facet stands for.
     *
     * @return list<string>
     */
    public function facets(): array
    {
        return array_map(static fn (Category $category): string => $category->value, Category::cases());
    }

    /**
     * The Game→Shop seam for BuyerInterface: composes the two sources of an
     * Entitlement (the player's append-only credit ledger — owned advances'
     * printed credits and the scenario's starting credits, both posted by
     * App\Engine\Shop\AdvanceFulfillment and App\Engine\Handler\
     * CreateGameHandler — plus elective allocations from validated orders)
     * into a PlayerBuyer snapshot.
     *
     * The ledger is replayed chronologically into one Entitlement per scope,
     * carrying that scope's final balance — see ledgerEntitlements() for the
     * walk. A straight per-entry sum cannot be trusted here: revoke() removes
     * the CreditEntry a grant posted outright rather than posting its
     * negative counterpart (see AdvanceFulfillment), which can retroactively
     * invalidate an intervening withdrawal's cap; only a chronological
     * replay resolves it correctly.
     *
     * A Pending order's own lines are excluded here — not in OptionCredits —
     * which is the load-bearing half of the no-self-crediting invariant: a
     * buyer must be built (or rebuilt) at the exact call site of a quote, and
     * never reused across a validate/erase/disown mutation, or it goes stale
     * and self-credits. Never cache this by player id across such a boundary.
     */
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
     * Replays the ledger chronologically into a final balance per scope. Each
     * entry either adds to its scope's running balance (value >= 0) or
     * withdraws from it, capped at whatever is currently available (never
     * below zero) — capping this at every step, rather than on the running
     * total, is what stops an intervening withdrawal from swallowing a later
     * gain: [+5, -10, +5] must settle at 5, never at 0.
     *
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
