<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\Dto\Advisory;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 20)]
final class TaxPaymentRule implements AdvisoryRule
{
    // Mirrors Player::CENSUS_MAX / Player::TREASURY_MAX (same shared 55-token pool).
    private const int TOTAL_TOKEN_STOCK = 55;

    public function evaluate(Player $player): ?Advisory
    {
        if (self::TOTAL_TOKEN_STOCK - $player->census - $player->treasury < 2 * $player->cities) {
            return new Advisory("You can't pay your taxes!");
        }

        return null;
    }
}
