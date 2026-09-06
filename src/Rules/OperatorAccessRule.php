<?php

declare(strict_types=1);

namespace App\Rules;

use App\State\Game;

final class OperatorAccessRule
{
    public function hash(string $pin): string
    {
        return password_hash($pin, PASSWORD_DEFAULT);
    }

    public function unlocks(Game $game, string $pin): bool
    {
        if (null === $game->operatorPinHash) {
            return false;
        }

        return password_verify($pin, $game->operatorPinHash);
    }
}
