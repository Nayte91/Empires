<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\State\Game;
use App\State\Player;
use Symfony\Component\Uid\Uuid;
use Twig\Attribute\AsTwigFunction;

final readonly class GameTopics
{
    private const string PREFIX = 'empires/game/';

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
