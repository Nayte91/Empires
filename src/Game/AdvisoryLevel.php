<?php

declare(strict_types=1);

namespace App\Game;

/**
 * How an advisory should land, from routine to urgent. This is meaning, not presentation: the view
 * alone turns a level into a colour, and an advisory that carried one would have stopped being a
 * domain answer.
 */
enum AdvisoryLevel: string
{
    /** Business as usual, nothing to act on. */
    case Neutral = 'neutral';

    /** A position the player benefits from. */
    case Good = 'good';

    /** Not broken yet, but one step away. */
    case Caution = 'caution';

    /** A rule already broken, demanding a correction. */
    case Danger = 'danger';

    /**
     * Reading order in the outlook: what is already broken first, what reads well last. Lower
     * sorts higher.
     */
    public function urgency(): int
    {
        return match ($this) {
            self::Danger => 0,
            self::Caution => 1,
            self::Neutral => 2,
            self::Good => 3,
        };
    }
}
