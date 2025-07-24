<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;

/** @extends SessionManager<\App\Entity\Game> */
final class GameRepository extends SessionManager
{
    public function findOrCreate(): Game
    {
        return $this->find() ?? new Game;
    }

    public function updatePlayerInfo(): Game
    {
        $gameSession = $this->findOrCreate();
        $this->save($gameSession);

        return $gameSession;
    }

    public function updateGameSettings(): Game
    {
        $gameSession = $this->findOrCreate();
        $this->save($gameSession);

        return $gameSession;
    }

    public function updateAdvances(): Game
    {
        $gameSession = $this->findOrCreate();
        $this->save($gameSession);

        return $gameSession;
    }
}
