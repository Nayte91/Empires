<?php

namespace App\DTO;

class UserSettings
{
    public ?string $playerName = null;
    public ?string $civilizationName = null;
    public ?string $civilizationColor = null;
    public ?int $civilizationPosition = null;
    public int $playerCount = 9;
    public ?string $region = null;
    public int $currentTurn = 1;
    public string $astType = 'basic';

    public array $advances = [];
    public array $resources = [];
}
