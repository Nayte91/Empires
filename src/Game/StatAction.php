<?php

declare(strict_types=1);

namespace App\Game;

use App\Entity\Player;

/**
 * One-click game operations offered inside a stat's picker dialog, on top of the raw value grid.
 *
 * Every arithmetic rule lives here rather than in the template: the "disabled" state and the
 * applied result are two readings of the same rule, and computing them in two places is how they
 * drift apart.
 */
enum StatAction: string
{
    case AstForward = 'astForward';
    case AstBackward = 'astBackward';
    case CensusDouble = 'censusDouble';
    case PayTaxes = 'payTaxes';
    case BuildShip = 'buildShip';
    case MaintainShips = 'maintainShips';
    case DrawCards = 'drawCards';
    case CutToLimit = 'cutToLimit';

    // REFACTOR-WHEN: a 5th reader of the 55-token pool appears — Player::CENSUS_MAX,
    // Player::TREASURY_MAX and TaxPaymentRule::TOTAL_TOKEN_STOCK are the other three, each
    // spelling the same game fact. Promote it to one shared constant instead.
    private const int TOKEN_POOL = Player::CENSUS_MAX;
    private const int SHIP_COST = 2;

    /** @return list<self> */
    public static function forStat(Stat $stat): array
    {
        return match ($stat) {
            Stat::AstPosition => [self::AstBackward, self::AstForward],
            Stat::Census => [self::CensusDouble],
            Stat::Treasury => [self::PayTaxes],
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
            self::PayTaxes => 'Pay taxes',
            self::BuildShip => 'Create one',
            self::MaintainShips => 'Maintain existings',
            self::DrawCards => 'Draw',
            self::CutToLimit => 'Cut to limit',
        };
    }

    public function isAvailable(Player $player, int $handLimit): bool
    {
        return match ($this) {
            self::AstForward => $player->astPosition < Player::AST_MAX,
            self::AstBackward => $player->astPosition > Player::AST_MIN,
            self::CensusDouble => $this->doubledCensus($player) > $player->census,
            self::PayTaxes => $this->taxedTreasury($player) > $player->treasury,
            self::BuildShip => $player->ships < Player::SHIPS_MAX && $player->treasury >= self::SHIP_COST,
            self::MaintainShips => $player->ships > 0 && $player->treasury >= $player->ships,
            self::DrawCards => $player->cities > 0,
            self::CutToLimit => $player->cards > $handLimit,
        };
    }

    /**
     * Every target is clamped rather than trusted: the action name travels as a client-writable
     * prop, so an unavailable action can still reach us and must degrade to a no-op, never to a
     * gain the player did not earn.
     */
    public function apply(Player $player, int $handLimit): void
    {
        match ($this) {
            self::AstForward => ++$player->astPosition,
            self::AstBackward => --$player->astPosition,
            self::CensusDouble => $player->census = $this->doubledCensus($player),
            self::PayTaxes => $player->treasury = $this->taxedTreasury($player),
            self::BuildShip => $this->buildShip($player),
            self::MaintainShips => $player->treasury -= $player->ships,
            self::DrawCards => $player->cards += $player->cities,
            self::CutToLimit => $player->cards = min($player->cards, $handLimit),
        };
    }

    /** The only action moving two stats, hence the helper: a match arm holds one expression. */
    private function buildShip(Player $player): int
    {
        $player->treasury -= self::SHIP_COST;

        return ++$player->ships;
    }

    /** Census and treasury draw from one 55-token stock, so doubling stops at what is left of it. */
    private function doubledCensus(Player $player): int
    {
        return max($player->census, min($player->census * 2, self::TOKEN_POOL - $player->treasury));
    }

    private function taxedTreasury(Player $player): int
    {
        return max($player->treasury, min($player->treasury + 2 * $player->cities, self::TOKEN_POOL - $player->census));
    }
}
