<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\State\Game;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/** It is also the one screen still answering for a finished game, so its title qualifier says so. */
#[AsLiveComponent(name: 'molecules:GameHeading', template: 'molecules/GameHeading.html.twig')]
final class GameHeading
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)
}
