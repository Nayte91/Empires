<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\State\Player;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Advisories belong to the Outlook block; this one is controls only. */
#[AsTwigComponent(name: 'molecules:ControlBoard', template: 'molecules/ControlBoard.html.twig')]
final class ControlBoard
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    /** Off by default: a link to a player's own shop only makes sense on that player's own board. */
    public bool $withShopLink = false;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator) {}

    public function getShopUrl(): string
    {
        return $this->urlGenerator->generate('app_player_shop', [
            'gameSlug' => $this->player->game->slug,
            'playerSlug' => $this->player->slug,
        ]);
    }
}
