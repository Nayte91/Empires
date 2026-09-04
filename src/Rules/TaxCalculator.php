<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Action\Stat;
use App\Rules\Ruleset\AdvanceEffect;
use App\Rules\Ruleset\AdvanceEffectRegistry;
use App\State\Player;

/**
 * The game's single authority on taxation. Every consumer — the advisory, the "Pay taxes" controls
 * and the outlook board — asks this object rather than re-deriving the bill, so a rule change
 * lands in one place.
 */
final readonly class TaxCalculator
{
    private const int STANDARD_RATE = 2;

    public function __construct(
        private StockCalculator $stockCalculator,
        private AdvanceEffectRegistry $advanceEffects,
    ) {}

    public function lowestRate(Player $player): int
    {
        return self::STANDARD_RATE - ($this->grants($player, AdvanceEffect::TaxRateChoice) ? 1 : 0);
    }

    /** @return list<int> */
    public function rates(Player $player): array
    {
        return range($this->lowestRate($player), $this->highestRate($player));
    }

    public function billAt(Player $player, int $rate): int
    {
        return $rate * $player->cities;
    }

    public function availableStock(Player $player): int
    {
        return $this->stockCalculator->available($player);
    }

    public function stockToRecover(Player $player): int
    {
        return max(0, $this->billAt($player, $this->lowestRate($player)) - $this->availableStock($player));
    }

    public function isImmune(Player $player): bool
    {
        return $this->grants($player, AdvanceEffect::TaxRevoltImmunity);
    }

    public function citiesRevolt(Player $player): bool
    {
        return !$this->isImmune($player) && $this->stockToRecover($player) > 0;
    }

    public function collectedAt(Player $player, int $rate): int
    {
        return max($player->treasury, min(
            $player->treasury + $this->billAt($player, $rate),
            $this->stockCalculator->ceilingFor($player, Stat::Treasury),
        ));
    }

    private function highestRate(Player $player): int
    {
        return self::STANDARD_RATE
            + ($this->grants($player, AdvanceEffect::TaxRateChoice) ? 1 : 0)
            + ($this->grants($player, AdvanceEffect::TaxRateRaise) ? 1 : 0);
    }

    private function grants(Player $player, AdvanceEffect $effect): bool
    {
        return $this->advanceEffects->grants($player->advances, $effect);
    }
}
