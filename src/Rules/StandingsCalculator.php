<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\AdvanceCatalog;
use App\State\GameSession;
use App\State\Player;

/**
 * The game's single authority on the race: who leads, by how much, and where each player sits.
 *
 * Distinct from {@see ScoreCalculator}, which only answers "how many points". Scoring is a formula
 * over one player; standing is a comparison across the table, and it needs the advance catalogue
 * to resolve what each of them owns.
 */
final readonly class StandingsCalculator
{
    public function __construct(
        private AdvanceCatalog $advanceCatalog,
        private ScoreCalculator $scoreCalculator,
    ) {}

    /** The player's score, resolving their advances so callers do not have to. */
    public function scoreOf(Player $player): int
    {
        return $this->scoreCalculator->scoreFor(
            $player,
            array_values($this->advanceCatalog->getAdvancesByNames($player->advances)),
        );
    }

    /**
     * The table ordered by score, leader first. The one place the ranking is decided, so the score
     * board and the outlook board can never disagree on who leads.
     *
     * @return list<Player>
     */
    public function standings(GameSession $game): array
    {
        $players = $game->players->toArray();
        usort($players, fn (Player $a, Player $b): int => $this->scoreOf($b) <=> $this->scoreOf($a));

        return $players;
    }

    /** 1-based place in the standings, counting from the leader. */
    public function rankOf(Player $player): int
    {
        $rank = array_search($player, $this->standings($player->game), true);

        return \is_int($rank) ? $rank + 1 : 1;
    }

    /** How far behind the leader the player sits, 0 when they lead or share the lead. */
    public function gapToLeader(Player $player): int
    {
        return $this->scoreOf($this->standings($player->game)[0]) - $this->scoreOf($player);
    }

    /**
     * How far ahead of the runner-up the leader sits, or null when there is no runner-up at all.
     * Null and zero are different answers: an empty table is not a tie.
     */
    public function leadOverRunnerUp(Player $player): ?int
    {
        $standings = $this->standings($player->game);

        return isset($standings[1]) ? $this->scoreOf($player) - $this->scoreOf($standings[1]) : null;
    }
}
