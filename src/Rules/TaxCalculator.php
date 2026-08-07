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
 *
 * The rate is no longer a constant: two advances widen it into a range the player picks from each
 * turn. Only its floor matters to the alarms — a player who may pay less is only at risk when even
 * the cheapest rate is out of reach.
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

    /**
     * The dearest rate the player may elect to pay. Nothing consumes it yet — paying above the
     * standard rate has no effect the application models — but the rule belongs here rather than
     * in the head of whoever implements it.
     */
    public function highestRate(Player $player): int
    {
        return self::STANDARD_RATE
            + ($this->grants($player, AdvanceEffect::TaxRateChoice) ? 1 : 0)
            + ($this->grants($player, AdvanceEffect::TaxRateRaise) ? 1 : 0);
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

    /** Tokens to return to the stock before the cheapest bill becomes payable, 0 when it already is. */
    public function stockToRecover(Player $player): int
    {
        return max(0, $this->billAt($player, $this->lowestRate($player)) - $this->availableStock($player));
    }

    /**
     * Two consequences the rest of this class encodes: collection is untouched, and the shortage
     * itself still exists — only the revolt it would cause is cancelled.
     */
    public function isImmune(Player $player): bool
    {
        return $this->grants($player, AdvanceEffect::TaxRevoltImmunity);
    }

    public function citiesRevolt(Player $player): bool
    {
        return !$this->isImmune($player) && $this->stockToRecover($player) > 0;
    }

    /**
     * Treasury once the bill is collected at the given rate — taxes are collected "as usual"
     * whatever the player owns, so immunity plays no part here.
     */
    public function collectedAt(Player $player, int $rate): int
    {
        return max($player->treasury, min(
            $player->treasury + $this->billAt($player, $rate),
            $this->stockCalculator->ceilingFor($player, Stat::Treasury),
        ));
    }

    private function grants(Player $player, AdvanceEffect $effect): bool
    {
        return $this->advanceEffects->grants($player->advances, $effect);
    }
}
