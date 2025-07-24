<?php

namespace App\Entity;

class Civilization {
    public int $position;
    public string $name;
    public string $demonym;
    public string $adjective;
    public ?string $peopleIcon;
    public ?string $shipIcon;
    public ?string $cityIcon;
    public string $color;
}
