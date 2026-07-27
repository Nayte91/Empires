<?php

declare(strict_types=1);

namespace App\Game\Service;

use App\Entity\Player;
use App\Game\Stat;

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
    /**
     * Democracy, verbatim: "During the tax collection phase you collect taxes as usual but your
     * cities do not revolt as a result of a shortage in tax collection.".
     *
     * Two consequences the rest of this class encodes: collection is untouched, and the shortage
     * itself still exists — only the revolt it would cause is cancelled.
     */
    public const string IMMUNITY_ADVANCE = 'democracy';

    /** Coinage lets its owner move the rate one either way. */
    public const string RATE_CHOICE_ADVANCE = 'coinage';

    /** Monarchy lets its owner raise the rate by one, and stacks with Coinage. */
    public const string RATE_RAISE_ADVANCE = 'monarchy';

    // REFACTOR-WHEN: a 7th advance key gets hardcoded in a service — military, democracy,
    // architecture, roadbuilding, coinage and monarchy are the six so far, spread over four
    // calculators. config/game/advances.yaml already models per-advance effects (mitigation,
    // aggravation); declare these there and let the services read them instead.

    private const int STANDARD_RATE = 2;

    public function __construct(private StockCalculator $stockCalculator) {}

    /** The cheapest rate the player may elect to pay. */
    public function lowestRate(Player $player): int
    {
        return self::STANDARD_RATE - ($this->owns($player, self::RATE_CHOICE_ADVANCE) ? 1 : 0);
    }

    /**
     * The dearest rate the player may elect to pay. Nothing consumes it yet — paying above the
     * standard rate has no effect the application models — but the rule belongs here rather than
     * in the head of whoever implements it.
     */
    public function highestRate(Player $player): int
    {
        return self::STANDARD_RATE
            + ($this->owns($player, self::RATE_CHOICE_ADVANCE) ? 1 : 0)
            + ($this->owns($player, self::RATE_RAISE_ADVANCE) ? 1 : 0);
    }

    /**
     * Every rate the player may choose between, cheapest first.
     *
     * @return list<int>
     */
    public function rates(Player $player): array
    {
        return range($this->lowestRate($player), $this->highestRate($player));
    }

    public function billAt(Player $player, int $rate): int
    {
        return $rate * $player->cities;
    }

    /** Tokens still on the table, available to settle the bill. */
    public function availableStock(Player $player): int
    {
        return $this->stockCalculator->available($player);
    }

    /** Tokens to return to the stock before the cheapest bill becomes payable, 0 when it already is. */
    public function stockToRecover(Player $player): int
    {
        return max(0, $this->billAt($player, $this->lowestRate($player)) - $this->availableStock($player));
    }

    public function isImmune(Player $player): bool
    {
        return $this->owns($player, self::IMMUNITY_ADVANCE);
    }

    /**
     * Whether the shortage would actually cost the player cities. The one question consumers
     * should ask: a shortage alone does not answer it, immunity settles it first.
     */
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

    private function owns(Player $player, string $advance): bool
    {
        return \in_array($advance, $player->advances, true);
    }
}
