<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Game\Category;
use App\Game\Dto\Advance;

final class PriceCalculator
{
    // Starting credits granted by config/game/scenarios.yaml, and the 'pack'/'promotion'
    // YAML keys, are intentionally out of scope for this v1 shop pricing.

    /** @param list<Advance> $owned */
    public function netCost(Advance $advance, array $owned): int
    {
        $net = $advance->cost - $this->categoryCredits($advance, $owned) - $this->namedCredits($advance, $owned);

        return max(0, $net);
    }

    /**
     * @param list<Advance> $inOrder
     * @param list<Advance> $owned
     */
    public function orderTotal(array $inOrder, array $owned): int
    {
        $total = 0;

        foreach ($inOrder as $advance) {
            $total += $this->netCost($advance, $owned);
        }

        return $total;
    }

    /**
     * Aggregates every credit granted by $owned, globally rather than against a single
     * priced advance: category credits sum across all owned (no per-product max), and
     * every non-category credit key is treated as a named credit toward that advance slug.
     *
     * @param list<Advance> $owned
     *
     * @return array{categories: array<string, int>, named: array<string, int>}
     */
    public function creditsFor(array $owned): array
    {
        $categories = [];

        foreach (Category::cases() as $category) {
            $categories[$category->value] = $this->sumCreditsFor($category->value, $owned);
        }

        $named = [];

        foreach ($owned as $ownedAdvance) {
            /** @var array<string, int> $credits */
            $credits = $ownedAdvance->credits;

            foreach ($credits as $key => $value) {
                if (null !== Category::tryFrom($key)) {
                    continue;
                }

                $named[$key] = ($named[$key] ?? 0) + $value;
            }
        }

        return ['categories' => $categories, 'named' => $named];
    }

    /** @param list<Advance> $owned */
    private function categoryCredits(Advance $advance, array $owned): int
    {
        /** @var list<string> $categories */
        $categories = $advance->categories;

        $best = 0;

        foreach ($categories as $category) {
            $best = max($best, $this->sumCreditsFor($category, $owned));
        }

        return $best;
    }

    /** @param list<Advance> $owned */
    private function namedCredits(Advance $advance, array $owned): int
    {
        return $this->sumCreditsFor($advance->key, $owned);
    }

    /** @param list<Advance> $owned */
    private function sumCreditsFor(string $creditKey, array $owned): int
    {
        $sum = 0;

        foreach ($owned as $ownedAdvance) {
            /** @var array<string, int> $credits */
            $credits = $ownedAdvance->credits;
            $sum += $credits[$creditKey] ?? 0;
        }

        return $sum;
    }
}
