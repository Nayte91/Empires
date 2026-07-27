<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\AdvisoryLevel;
use App\Game\Dto\Advisory;
use App\Game\Service\CitySupportCalculator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 30)]
final readonly class CitySupportRule implements AdvisoryRule
{
    public function __construct(private CitySupportCalculator $citySupportCalculator) {}

    public function evaluate(Player $player): ?Advisory
    {
        if ($this->citySupportCalculator->citiesAreUnsupported($player)) {
            return new Advisory("You can't support your cities!", AdvisoryLevel::Danger);
        }

        return null;
    }
}
