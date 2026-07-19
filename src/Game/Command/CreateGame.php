<?php

declare(strict_types=1);

namespace App\Game\Command;

use App\Game\Validator\AvailableGameSlug;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateGame
{
    #[AvailableGameSlug]
    public string $slug = '';

    public int $playerCount = 9;
    public ?string $region = 'west';

    #[Assert\Choice(choices: ['basic', 'expert'])]
    public string $astVersion = 'basic';

    /** @var list<array{name: string, empire: string}> */
    public array $players = [];
}
