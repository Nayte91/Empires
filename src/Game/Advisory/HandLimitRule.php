<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\Dto\Advisory;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 10)]
final class HandLimitRule implements AdvisoryRule
{
    private const int HAND_LIMIT = 8;
    private const int LARGE_GAME_HAND_LIMIT = 9;
    private const int LARGE_GAME_PLAYER_THRESHOLD = 12;

    // REFACTOR-WHEN: a 3rd hand-limit bracket lands (e.g. an empire/advance-based modifier on top of the player-count one) — read from config/game data instead of these constants.

    public function evaluate(Player $player): ?Advisory
    {
        $limit = $player->game->playerCount >= self::LARGE_GAME_PLAYER_THRESHOLD
            ? self::LARGE_GAME_HAND_LIMIT
            : self::HAND_LIMIT;

        if ($player->cards > $limit) {
            return new Advisory('You must discard a card!');
        }

        return null;
    }
}
