<?php

declare(strict_types=1);

namespace App\Rules\Advisory;

use App\Rules\StandingsCalculator;
use App\State\Player;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The player's place in the race, worded as a championship standing: the leader reads their margin
 * over the runner-up, everyone else reads their deficit to the leader.
 */
#[AsTaggedItem(priority: -5)]
final readonly class ScoreStandingRule implements AdvisoryRule
{
    public function __construct(private StandingsCalculator $standingsCalculator) {}

    public function evaluate(Player $player): Advisory
    {
        if ($this->standingsCalculator->gapToLeader($player) > 0) {
            return new Advisory(
                sprintf(
                    'You are %s, %d VP behind the leader',
                    new \NumberFormatter('en', \NumberFormatter::ORDINAL)->format($this->standingsCalculator->rankOf($player)),
                    $this->standingsCalculator->gapToLeader($player),
                ),
                AdvisoryLevel::Neutral,
            );
        }

        $lead = $this->standingsCalculator->leadOverRunnerUp($player);

        if (null === $lead) {
            return new Advisory('You lead the game', AdvisoryLevel::Good);
        }

        if (0 === $lead) {
            return new Advisory('You share the lead', AdvisoryLevel::Good);
        }

        return new Advisory(sprintf('You lead by %d VP', $lead), AdvisoryLevel::Good);
    }
}
