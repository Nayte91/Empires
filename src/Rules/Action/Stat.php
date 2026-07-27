<?php

declare(strict_types=1);

namespace App\Rules\Action;

use App\State\Player;

enum Stat: string
{
    case Cities = 'cities';
    case Census = 'census';
    case Treasury = 'treasury';
    case Ships = 'ships';
    case Cards = 'cards';
    case AstPosition = 'astPosition';

    public function read(Player $player): int
    {
        return match ($this) {
            self::Cities => $player->cities,
            self::Census => $player->census,
            self::Treasury => $player->treasury,
            self::Ships => $player->ships,
            self::Cards => $player->cards,
            self::AstPosition => $player->astPosition,
        };
    }

    public function write(Player $player, int $value): void
    {
        match ($this) {
            self::Cities => $player->cities = $value,
            self::Census => $player->census = $value,
            self::Treasury => $player->treasury = $value,
            self::Ships => $player->ships = $value,
            self::Cards => $player->cards = $value,
            self::AstPosition => $player->astPosition = $value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cities => 'Cities',
            self::Census => 'Population',
            self::Treasury => 'Treasury',
            self::Ships => 'Ships',
            self::Cards => 'Cards',
            self::AstPosition => 'AST',
        };
    }

    public function format(int $value): string
    {
        return match ($this) {
            self::AstPosition => (string) ($value + 1),
            default => (string) $value,
        };
    }
}
