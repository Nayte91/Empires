<?php

declare(strict_types=1);

namespace App\Rules\Scenario;

use App\Rules\Action\CreateGame;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.scenario_rule')]
interface ScenarioRule
{
    public function describe(CreateGame $game): string;
}
