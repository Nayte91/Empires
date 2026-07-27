<?php

declare(strict_types=1);

namespace App\Game\Service;

use App\Game\Command\CreateGame;
use App\Game\Scenario\ScenarioRule;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ScenarioRuleSummarizer
{
    /** @param iterable<ScenarioRule> $rules */
    public function __construct(#[AutowireIterator('app.scenario_rule')] private iterable $rules) {}

    /** @return list<string> */
    public function summarize(CreateGame $game): array
    {
        $summaries = [];

        foreach ($this->rules as $rule) {
            $summaries[] = $rule->describe($game);
        }

        return $summaries;
    }
}
