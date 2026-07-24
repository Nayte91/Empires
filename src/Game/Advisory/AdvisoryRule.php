<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\Dto\Advisory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.advisory_rule')]
interface AdvisoryRule
{
    public function evaluate(Player $player): ?Advisory;
}
