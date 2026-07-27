<?php

declare(strict_types=1);

namespace App\Rules\Advisory;

use App\State\Player;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.advisory_rule')]
interface AdvisoryRule
{
    public function evaluate(Player $player): ?Advisory;
}
