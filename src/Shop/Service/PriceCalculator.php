<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Game\Dto\Advance;
use App\Shop\Dto\OrderLine;

final class PriceCalculator
{
    // Starting credits granted by config/game/scenarios.yaml, and the 'payment' YAML key,
    // are intentionally out of scope for this v1 shop pricing. 'promotion' is handled
    // downstream by App\Shop\Promotion\PromotionEngine, not here.

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits extra credits granted by validated
     *                                         Option allocations (App\Shop\Promotion\OptionCredits),
     *                                         merged into the category totals alongside $owned
     */
    public function netCost(Advance $advance, array $owned, array $bonusCredits = []): int
    {
        $net = $advance->cost - $this->categoryCredits($advance, $owned, $bonusCredits) - $this->namedCredits($advance, $owned, $bonusCredits);

        return max(0, $net);
    }

    /**
     * @param list<Advance>      $advances
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     *
     * @return list<OrderLine>
     */
    public function priceLines(array $advances, array $owned, array $bonusCredits = []): array
    {
        return array_map(
            fn (Advance $advance): OrderLine => new OrderLine($advance->key, $this->netCost($advance, $owned, $bonusCredits)),
            $advances,
        );
    }

    /**
     * @param list<Advance>      $inOrder
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    public function orderTotal(array $inOrder, array $owned, array $bonusCredits = []): int
    {
        $total = 0;

        foreach ($inOrder as $advance) {
            $total += $this->netCost($advance, $owned, $bonusCredits);
        }

        return $total;
    }

    /**
     * Aggregates every credit granted by $owned, globally rather than against a single
     * priced advance: category credits sum across all owned (no per-product max), and
     * every non-category credit key is treated as a named credit toward that advance slug.
     *
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     * @param list<string>       $buckets      valid category buckets (the Game→Shop seam for the
     *                                         generic "bucket" concept, see ShopConnector::buckets())
     *
     * @return array{categories: array<string, int>, named: array<string, int>}
     */
    public function creditsFor(array $owned, array $bonusCredits = [], array $buckets = []): array
    {
        $categories = [];

        foreach ($buckets as $bucket) {
            $categories[$bucket] = $this->sumCreditsFor($bucket, $owned, $bonusCredits);
        }

        $named = [];

        foreach ($owned as $ownedAdvance) {
            /** @var array<string, int> $credits */
            $credits = $ownedAdvance->credits;

            foreach ($credits as $key => $value) {
                if (\in_array($key, $buckets, true)) {
                    continue;
                }

                $named[$key] = ($named[$key] ?? 0) + $value;
            }
        }

        return ['categories' => $categories, 'named' => $named];
    }

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    private function categoryCredits(Advance $advance, array $owned, array $bonusCredits = []): int
    {
        /** @var list<string> $categories */
        $categories = $advance->categories;

        $best = 0;

        foreach ($categories as $category) {
            $best = max($best, $this->sumCreditsFor($category, $owned, $bonusCredits));
        }

        return $best;
    }

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    private function namedCredits(Advance $advance, array $owned, array $bonusCredits = []): int
    {
        return $this->sumCreditsFor($advance->key, $owned, $bonusCredits);
    }

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    private function sumCreditsFor(string $creditKey, array $owned, array $bonusCredits = []): int
    {
        $sum = 0;

        foreach ($owned as $ownedAdvance) {
            /** @var array<string, int> $credits */
            $credits = $ownedAdvance->credits;
            $sum += $credits[$creditKey] ?? 0;
        }

        return $sum + ($bonusCredits[$creditKey] ?? 0);
    }
}
