<?php

declare(strict_types=1);

namespace App\Rules\Action;

use App\Rules\Action\Validator\AvailableGameSlug;
use App\Rules\Action\Validator\KnownScenario;
use App\State\Game;
use App\State\Region;
use Symfony\Component\Validator\Constraints as Assert;

#[KnownScenario]
final class CreateGame
{
    #[AvailableGameSlug]
    #[Assert\Length(max: Game::MAX_SLUG_LENGTH, maxMessage: 'The address this name builds is longer than {{ limit }} characters.')]
    public string $slug = '';

    public int $playerCount = 9;

    /**
     * A writable LiveComponent path may only carry a scalar, so the region travels as a raw string
     * and the enum never sees a crafted value: it dies here instead.
     */
    #[Assert\Choice(callback: [Region::class, 'values'], message: 'This region is not one this game offers.')]
    public ?string $region = 'west';

    #[Assert\Choice(choices: ['basic', 'expert'])]
    public string $astVersion = 'basic';

    /** @var list<array{name: string, empire: string}> */
    public array $players = [];
}
