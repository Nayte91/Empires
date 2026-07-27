<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\AdvisoryLevel;
use App\Game\Dto\Advisory;
use App\Game\Service\CensusOrderCalculator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Moving late is an advantage — you act knowing what everyone else did — so the rank is graded
 * rather than merely stated: last is good news, first is a position to watch.
 */
#[AsTaggedItem(priority: -10)]
final readonly class MovementOrderRule implements AdvisoryRule
{
    public function __construct(private CensusOrderCalculator $censusOrderCalculator) {}

    public function evaluate(Player $player): Advisory
    {
        $rank = $this->censusOrderCalculator->rankOf($player);
        $because = $this->censusOrderCalculator->hasMilitary($player) ? CensusOrderCalculator::MILITARY_ADVANCE : null;

        if ($rank === $player->game->players->count()) {
            return new Advisory('You play last this turn', AdvisoryLevel::Good, $because);
        }

        return new Advisory(
            sprintf('You play %s this turn', new \NumberFormatter('en', \NumberFormatter::ORDINAL)->format($rank)),
            1 === $rank ? AdvisoryLevel::Caution : AdvisoryLevel::Neutral,
            $because,
        );
    }
}
