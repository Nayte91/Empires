<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\Dto\Advisory;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 30)]
final class CitySupportRule implements AdvisoryRule
{
    public function evaluate(Player $player): ?Advisory
    {
        if ($player->census < 2 * $player->cities) {
            return new Advisory("You can't support your cities!");
        }

        return null;
    }
}
