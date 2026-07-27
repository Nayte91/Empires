<?php

declare(strict_types=1);

namespace App\Rules\Advisory;

use App\Rules\CitySupportCalculator;
use App\State\Player;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The quantitative twin of {@see CitySupportRule}: the same threshold read as a margin rather than
 * as an alarm. Both ask the same authority, so they cannot disagree.
 */
#[AsTaggedItem(priority: -20)]
final readonly class CitySupportMarginRule implements AdvisoryRule
{
    public function __construct(private CitySupportCalculator $citySupportCalculator) {}

    public function evaluate(Player $player): ?Advisory
    {
        if ($this->citySupportCalculator->citiesAreUnsupported($player)) {
            return null;
        }

        $spare = $this->citySupportCalculator->spareCensus($player);

        if (0 === $spare) {
            return new Advisory('You cannot afford to lose any population', AdvisoryLevel::Caution);
        }

        return new Advisory(sprintf('You are %d population over your city count', $spare), AdvisoryLevel::Neutral);
    }
}
