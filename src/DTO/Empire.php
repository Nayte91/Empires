<?php

declare(strict_types=1);

namespace App\DTO;

class Empire
{
    public int $position = 0;
    public string $name = '';
    public string $demonym = '';
    public string $adjective = '';
    public ?string $peopleIcon = null;
    public ?string $shipIcon = null;
    public ?string $cityIcon = null;
    public string $color = '';
}
