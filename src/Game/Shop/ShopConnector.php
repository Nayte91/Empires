<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Category;
use App\Game\ScenarioCatalog;
use App\Repository\OrderRepository;
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
    private const string OWNED_ADVANCE_SOURCE_PREFIX = 'advance:';
    private const string ELECTIVE_SOURCE = 'elective';
    private const string SCENARIO_SOURCE = 'scenario';

    public function __construct(
        private OrderRepository $orderRepository,
        private AdvanceCatalog $advanceCatalog,
        private ScenarioCatalog $scenarioCatalog,
    ) {}

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
     * The Game→Shop seam for BuyerInterface: composes the three sources of
     * an Entitlement (owned advances, elective allocations from validated
     * orders, and the scenario's starting credits) into a PlayerBuyer
     * snapshot.
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
                ...$this->ownedAdvancesEntitlements($player->advances),
                ...$this->electiveEntitlements($confirmedLines),
                ...$this->startingCreditsEntitlements($player->game->playerCount),
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
     * @param list<string> $ownedKeys
     *
     * @return list<Entitlement>
     */
    private function ownedAdvancesEntitlements(array $ownedKeys): array
    {
        $entitlements = [];

        foreach (array_values($this->advanceCatalog->getAdvancesByNames($ownedKeys)) as $advance) {
            foreach ($advance->credits as $scope => $value) {
                $entitlements[] = new Entitlement($scope, $value, self::OWNED_ADVANCE_SOURCE_PREFIX.$advance->key);
            }
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
            $entitlements[] = new Entitlement($scope, $value, self::ELECTIVE_SOURCE);
        }

        return $entitlements;
    }

    /** @return list<Entitlement> */
    private function startingCreditsEntitlements(int $playerCount): array
    {
        $entitlements = [];

        foreach ($this->scenarioCatalog->startingCreditsFor($playerCount) as $scope => $value) {
            $entitlements[] = new Entitlement($scope, $value, self::SCENARIO_SOURCE);
        }

        return $entitlements;
    }
}
