<?php

declare(strict_types=1);

namespace App\Rules\Action;

use App\Rules\HandSizeCalculator;
use App\Rules\StockCalculator;
use App\Rules\TaxCalculator;
use App\State\Player;

/**
 * One-click game operations offered inside a stat's picker dialog, on top of the raw value grid.
 *
 * Every arithmetic rule lives here rather than in the template: the "disabled" state and the
 * applied result are two readings of the same rule, and computing them in two places is how they
 * drift apart. Taxation is the exception — it is the game's own rule, owned by {@see TaxCalculator}
 * and handed in, so this control can never disagree with the advisory or the outlook board.
 */
enum StatAction: string
{
    case AstForward = 'astForward';
    case AstBackward = 'astBackward';
    case CensusDouble = 'censusDouble';
    case PayTaxes1 = 'payTaxes1';
    case PayTaxes2 = 'payTaxes2';
    case PayTaxes3 = 'payTaxes3';
    case PayTaxes4 = 'payTaxes4';
    case BuildShip = 'buildShip';
    case MaintainShips = 'maintainShips';
    case DrawCards = 'drawCards';
    case CutToLimit = 'cutToLimit';

    private const int SHIP_COST = 2;

    /** @return list<self> */
    public static function forStat(Stat $stat): array
    {
        return match ($stat) {
            Stat::AstPosition => [self::AstBackward, self::AstForward],
            Stat::Census => [self::CensusDouble],
            Stat::Treasury => [self::PayTaxes1, self::PayTaxes2, self::PayTaxes3, self::PayTaxes4],
            Stat::Ships => [self::BuildShip, self::MaintainShips],
            Stat::Cards => [self::DrawCards, self::CutToLimit],
            Stat::Cities => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AstForward => 'Move forward',
            self::AstBackward => 'Move backward',
            self::CensusDouble => 'Double',
            self::PayTaxes1 => 'Pay 1 per city',
            self::PayTaxes2 => 'Pay 2 per city',
            self::PayTaxes3 => 'Pay 3 per city',
            self::PayTaxes4 => 'Pay 4 per city',
            self::BuildShip => 'Create one',
            self::MaintainShips => 'Maintain existings',
            self::DrawCards => 'Draw',
            self::CutToLimit => 'Cut to limit',
        };
    }

    /**
     * Whether the action belongs on this player's menu at all — distinct from being clickable.
     * A tax rate their advances do not unlock is absent, not greyed out.
     */
    public function isOffered(Player $player, TaxCalculator $taxCalculator): bool
    {
        $rate = match ($this) {
            self::PayTaxes1 => 1,
            self::PayTaxes2 => 2,
            self::PayTaxes3 => 3,
            self::PayTaxes4 => 4,
            default => null,
        };

        return null === $rate || \in_array($rate, $taxCalculator->rates($player), true);
    }

    public function isAvailable(Player $player, HandSizeCalculator $handSizeCalculator, StockCalculator $stockCalculator, TaxCalculator $taxCalculator): bool
    {
        return match ($this) {
            self::AstForward => $player->astPosition < Player::AST_MAX,
            self::AstBackward => $player->astPosition > Player::AST_MIN,
            self::CensusDouble => $this->doubledCensus($player, $stockCalculator) > $player->census,
            self::PayTaxes1 => $taxCalculator->collectedAt($player, 1) > $player->treasury,
            self::PayTaxes2 => $taxCalculator->collectedAt($player, 2) > $player->treasury,
            self::PayTaxes3 => $taxCalculator->collectedAt($player, 3) > $player->treasury,
            self::PayTaxes4 => $taxCalculator->collectedAt($player, 4) > $player->treasury,
            self::BuildShip => $player->ships < Player::SHIPS_MAX && $player->treasury >= self::SHIP_COST,
            self::MaintainShips => $player->ships > 0 && $player->treasury >= $player->ships,
            self::DrawCards => $player->cities > 0,
            self::CutToLimit => $handSizeCalculator->isOverLimit($player),
        };
    }

    /**
     * Every target is clamped rather than trusted: the action name travels as a client-writable
     * prop, so an unavailable action can still reach us and must degrade to a no-op, never to a
     * gain the player did not earn.
     */
    public function apply(Player $player, HandSizeCalculator $handSizeCalculator, StockCalculator $stockCalculator, TaxCalculator $taxCalculator): void
    {
        match ($this) {
            self::AstForward => ++$player->astPosition,
            self::AstBackward => --$player->astPosition,
            self::CensusDouble => $player->census = $this->doubledCensus($player, $stockCalculator),
            self::PayTaxes1 => $player->treasury = $taxCalculator->collectedAt($player, 1),
            self::PayTaxes2 => $player->treasury = $taxCalculator->collectedAt($player, 2),
            self::PayTaxes3 => $player->treasury = $taxCalculator->collectedAt($player, 3),
            self::PayTaxes4 => $player->treasury = $taxCalculator->collectedAt($player, 4),
            self::BuildShip => $this->buildShip($player),
            self::MaintainShips => $player->treasury -= $player->ships,
            self::DrawCards => $player->cards += $player->cities,
            self::CutToLimit => $player->cards = min($player->cards, $handSizeCalculator->limitFor($player)),
        };
    }

    /** The only action moving two stats, hence the helper: a match arm holds one expression. */
    private function buildShip(Player $player): int
    {
        $player->treasury -= self::SHIP_COST;

        return ++$player->ships;
    }

    /** Census and treasury draw from one stock, so doubling stops at what is left of it. */
    private function doubledCensus(Player $player, StockCalculator $stockCalculator): int
    {
        return max($player->census, min($player->census * 2, $stockCalculator->ceilingFor($player, Stat::Census)));
    }
}
