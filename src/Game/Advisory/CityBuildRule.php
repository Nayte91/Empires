<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\AdvisoryLevel;
use App\Game\Dto\Advisory;
use App\Game\Service\CityBuildCalculator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: -25)]
final readonly class CityBuildRule implements AdvisoryRule
{
    public function __construct(private CityBuildCalculator $cityBuildCalculator) {}

    public function evaluate(Player $player): Advisory
    {
        if (0 === $this->cityBuildCalculator->remainingCitySlots($player)) {
            return new Advisory('Your empire has all the cities it may hold', AdvisoryLevel::Good);
        }

        $affordable = $this->cityBuildCalculator->affordableCities($player);

        if (0 === $affordable) {
            return new Advisory('You cannot build any city', AdvisoryLevel::Neutral);
        }

        return new Advisory(
            1 === $affordable
                ? 'You can build 1 city'
                : sprintf('You can build up to %d cities', $affordable),
            AdvisoryLevel::Neutral,
        );
    }
}
