<?php

declare(strict_types=1);

namespace App\Game\Service;

use App\Entity\Player;

/**
 * The game's single authority on how many cards a hand may hold. The limit is no longer a property
 * of the table alone: a player's own advances raise it, so anything that quotes or enforces it —
 * the warning, the "Cut to limit" control, the game summary — asks here.
 */
final readonly class HandSizeCalculator
{
    /** Road Building lets its owner keep one card more than the table allows. */
    public const string EXTRA_CARD_ADVANCE = 'roadbuilding';

    // REFACTOR-WHEN: a 3rd bracket lands on top of the player-count one and the advance one —
    // read the whole table from config/game/game_data.yaml instead of these constants.
    private const int HAND_LIMIT = 8;
    private const int LARGE_GAME_HAND_LIMIT = 9;
    private const int LARGE_GAME_PLAYER_THRESHOLD = 12;

    /** What the table alone allows, before any player's own advances. */
    public function baseLimitFor(int $playerCount): int
    {
        return $playerCount >= self::LARGE_GAME_PLAYER_THRESHOLD
            ? self::LARGE_GAME_HAND_LIMIT
            : self::HAND_LIMIT;
    }

    public function limitFor(Player $player): int
    {
        return $this->baseLimitFor($player->game->playerCount)
            + (\in_array(self::EXTRA_CARD_ADVANCE, $player->advances, true) ? 1 : 0);
    }

    /** The one question consumers should ask. */
    public function isOverLimit(Player $player): bool
    {
        return $player->cards > $this->limitFor($player);
    }
}
