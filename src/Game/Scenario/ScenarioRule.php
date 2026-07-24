<?php

declare(strict_types=1);

namespace App\Game\Scenario;

use App\Game\Command\CreateGame;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.scenario_rule')]
interface ScenarioRule
{
    public function describe(CreateGame $game): string;
}
