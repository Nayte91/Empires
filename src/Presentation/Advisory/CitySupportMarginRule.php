<?php

declare(strict_types=1);

namespace App\Presentation\Advisory;

use App\Rules\CitySupportCalculator;
use App\State\Player;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The quantitative twin of {@see CitySupportRule}: the same threshold read as a margin rather than
 * as an alarm. Both ask the same authority, so they cannot disagree.
 *
 * The subtraction is this rule's own on purpose. A margin is advice, not a rule — the game never
 * asks how much slack a player holds, only whether the demand is met.
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

        // No clamp: the guard above has already established that the demand is met.
        $spare = $player->census - $this->citySupportCalculator->required($player);

        // "Up to 0" reads as a mistake, so the boundary gets its own sentence.
        if (0 === $spare) {
            return new Advisory('You cannot afford to lose any population', AdvisoryLevel::Caution);
        }

        return new Advisory(sprintf('You are %d population over your city count', $spare), AdvisoryLevel::Neutral);
    }
}
