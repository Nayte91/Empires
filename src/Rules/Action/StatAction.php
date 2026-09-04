<?php

declare(strict_types=1);

namespace App\Rules\Action;

use App\Rules\HandSizeCalculator;
use App\Rules\StatBoundsCalculator;
use App\Rules\TaxCalculator;
use App\State\Player;

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

    public function isAvailable(Player $player, HandSizeCalculator $handSizeCalculator, StatBoundsCalculator $statBoundsCalculator, TaxCalculator $taxCalculator): bool
    {
        return match ($this) {
            self::AstForward => $player->astPosition < $statBoundsCalculator->ceilingFor($player, Stat::AstPosition),
            self::AstBackward => $player->astPosition > $statBoundsCalculator->floorFor($player, Stat::AstPosition),
            self::CensusDouble => $this->doubledCensus($player, $statBoundsCalculator) > $player->census,
            self::PayTaxes1 => $taxCalculator->collectedAt($player, 1) > $player->treasury,
            self::PayTaxes2 => $taxCalculator->collectedAt($player, 2) > $player->treasury,
            self::PayTaxes3 => $taxCalculator->collectedAt($player, 3) > $player->treasury,
            self::PayTaxes4 => $taxCalculator->collectedAt($player, 4) > $player->treasury,
            self::BuildShip => $player->ships < $statBoundsCalculator->ceilingFor($player, Stat::Ships) && $player->treasury >= self::SHIP_COST,
            self::MaintainShips => $player->ships > 0 && $player->treasury >= $player->ships,
            self::DrawCards => $player->cities > 0,
            self::CutToLimit => $handSizeCalculator->isOverLimit($player),
        };
    }

    public function apply(Player $player, HandSizeCalculator $handSizeCalculator, StatBoundsCalculator $statBoundsCalculator, TaxCalculator $taxCalculator): void
    {
        match ($this) {
            self::AstForward => $player->astPosition = min($player->astPosition + 1, $statBoundsCalculator->ceilingFor($player, Stat::AstPosition)),
            self::AstBackward => $player->astPosition = max($player->astPosition - 1, $statBoundsCalculator->floorFor($player, Stat::AstPosition)),
            self::CensusDouble => $player->census = $this->doubledCensus($player, $statBoundsCalculator),
            self::PayTaxes1 => $player->treasury = $taxCalculator->collectedAt($player, 1),
            self::PayTaxes2 => $player->treasury = $taxCalculator->collectedAt($player, 2),
            self::PayTaxes3 => $player->treasury = $taxCalculator->collectedAt($player, 3),
            self::PayTaxes4 => $player->treasury = $taxCalculator->collectedAt($player, 4),
            self::BuildShip => $this->buildShip($player, $statBoundsCalculator),
            self::MaintainShips => $player->treasury = max($statBoundsCalculator->floorFor($player, Stat::Treasury), $player->treasury - $player->ships),
            self::DrawCards => $player->cards += $player->cities,
            self::CutToLimit => $player->cards = min($player->cards, $handSizeCalculator->limitFor($player)),
        };
    }

    private function buildShip(Player $player, StatBoundsCalculator $statBoundsCalculator): int
    {
        $player->treasury = max($statBoundsCalculator->floorFor($player, Stat::Treasury), $player->treasury - self::SHIP_COST);

        return $player->ships = min($player->ships + 1, $statBoundsCalculator->ceilingFor($player, Stat::Ships));
    }

    private function doubledCensus(Player $player, StatBoundsCalculator $statBoundsCalculator): int
    {
        return max($player->census, min($player->census * 2, $statBoundsCalculator->ceilingFor($player, Stat::Census)));
    }
}
