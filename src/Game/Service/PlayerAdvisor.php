<?php

declare(strict_types=1);

namespace App\Game\Service;

use App\Entity\Player;
use App\Game\Advisory\AdvisoryRule;
use App\Game\Dto\Advisory;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PlayerAdvisor
{
    /** @param iterable<AdvisoryRule> $rules */
    public function __construct(#[AutowireIterator('app.advisory_rule')] private iterable $rules) {}

    /** @return list<Advisory> */
    public function advisoriesFor(Player $player): array
    {
        $advisories = [];

        foreach ($this->rules as $rule) {
            $advisory = $rule->evaluate($player);

            if (null !== $advisory) {
                $advisories[] = $advisory;
            }
        }

        // Stable, so a rule's own priority still orders its peers within a level.
        usort($advisories, static fn (Advisory $a, Advisory $b): int => $a->level->urgency() <=> $b->level->urgency());

        return $advisories;
    }
}
