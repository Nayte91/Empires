<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\AdvanceEffect;
use App\Rules\Ruleset\AdvanceEffectRegistry;
use App\Rules\Ruleset\GameRegistry;
use App\State\Player;

/**
 * The game's single authority on how many cards a hand may hold. The limit is no longer a property
 * of the table alone: a player's own advances raise it, so anything that quotes or enforces it —
 * the warning, the "Cut to limit" control, the game summary — asks here.
 */
final readonly class HandSizeCalculator
{
    public function __construct(
        private GameRegistry $gameRegistry,
        private AdvanceEffectRegistry $advanceEffects,
    ) {}

    /**
     * What the table alone allows, before any player's own advances. Brackets are declared cheapest
     * first, so the last one the player count reaches is the one that applies; a count below the
     * first bracket falls back to it rather than to nothing.
     */
    public function baseLimitFor(int $playerCount): int
    {
        $brackets = $this->gameRegistry->getLimits()['hand_size_brackets'] ?? [];
        $limit = $brackets[0]['cards'] ?? 0;

        foreach ($brackets as $bracket) {
            if ($playerCount >= $bracket['from_players']) {
                $limit = $bracket['cards'];
            }
        }

        return $limit;
    }

    public function limitFor(Player $player): int
    {
        return $this->baseLimitFor($player->game->playerCount)
            + ($this->advanceEffects->grants($player->advances, AdvanceEffect::ExtraHandCard) ? 1 : 0);
    }

    /** The one question consumers should ask. */
    public function isOverLimit(Player $player): bool
    {
        return $player->cards > $this->limitFor($player);
    }
}
