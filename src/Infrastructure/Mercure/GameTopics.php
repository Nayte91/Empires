<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\State\Game;
use App\State\Player;
use Symfony\Component\Uid\Uuid;
use Twig\Attribute\AsTwigFunction;

/**
 * One topic per screen region, so a subscriber receives what its own screen shows and nothing else.
 * A single game-wide topic used to force the opposite: every open board woke on every mutation and
 * filtered client-side, re-rendering markup that could not have changed.
 *
 * The Twig function is what keeps the two ends honest — templates subscribe through the same
 * builder the publishers address, so a topic shape cannot drift on one side alone.
 */
final readonly class GameTopics
{
    private const string PREFIX = 'empires/game/';

    /** @return list<string> the regions every viewer of a game shares */
    public function shared(Uuid $gameId): array
    {
        return [$this->roster($gameId), $this->ast($gameId), $this->operator($gameId)];
    }

    public function roster(Uuid $gameId): string
    {
        return self::PREFIX.$gameId.'/roster';
    }

    public function ast(Uuid $gameId): string
    {
        return self::PREFIX.$gameId.'/ast';
    }

    public function operator(Uuid $gameId): string
    {
        return self::PREFIX.$gameId.'/operator';
    }

    public function board(Uuid $gameId, Uuid $playerId): string
    {
        return self::PREFIX.$gameId.'/player/'.$playerId;
    }

    public function shop(Uuid $gameId, Uuid $playerId): string
    {
        return self::PREFIX.$gameId.'/player/'.$playerId.'/shop';
    }

    #[AsTwigFunction('roster_topic')]
    public function rosterTopic(Game $game): string
    {
        return $this->roster($game->id);
    }

    #[AsTwigFunction('ast_topic')]
    public function astTopic(Game $game): string
    {
        return $this->ast($game->id);
    }

    #[AsTwigFunction('operator_topic')]
    public function operatorTopic(Game $game): string
    {
        return $this->operator($game->id);
    }

    #[AsTwigFunction('board_topic')]
    public function boardTopic(Player $player): string
    {
        return $this->board($player->game->id, $player->id);
    }

    #[AsTwigFunction('shop_topic')]
    public function shopTopic(Player $player): string
    {
        return $this->shop($player->game->id, $player->id);
    }
}
