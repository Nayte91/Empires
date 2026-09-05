<?php

declare(strict_types=1);

namespace App\State\Repository;

use App\State\Game;
use App\State\Order;
use App\State\Player;

interface OrderRepositoryInterface
{
    public function findOneByPlayerAndWindow(Player $player, int $window): ?Order;

    /** @return list<Order> */
    public function findByPlayer(Player $player): array;

    /** @return list<Order> */
    public function findByPlayerFromTurn(Player $player, int $turn): array;

    /** @return list<Order> */
    public function findByGame(Game $game): array;

    /** @return list<Order> */
    public function findValidatedByGame(Game $game): array;

    /** @return list<Order> */
    public function findValidatedByPlayer(Player $player): array;
}
